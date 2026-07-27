<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\PagesResults;
use App\Mcp\Concerns\PresentsDocs;
use App\Mcp\Concerns\ResolvesDocReferences;
use App\Models\Doc;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists the reference docs of a project, identified by its short_name (e.g. "PROJ"), optionally restricted to the docs nested directly under a "parent" doc (e.g. "PROJ-D3"). Each doc reports its own parent, so the nesting can be reconstructed. Drafts are listed only for members who may edit docs. The body is omitted here; use get-doc for a single doc\'s body.')]
#[IsReadOnly]
class ListDocsTool extends Tool
{
    use PagesResults;
    use PresentsDocs;
    use ResolvesDocReferences;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'parent' => ['nullable', 'string'],
            ...$this->pagingRules(),
        ], [
            'reference.required' => 'You must provide the project short_name (e.g. "PROJ").',
        ]);

        $offset = $this->decodePageCursor($validated['cursor'] ?? null);

        if ($offset === null) {
            return Response::error('The "cursor" is not a valid pagination cursor. Use the page.next_cursor from a previous response.');
        }

        $limit = $validated['limit'] ?? null;
        $user = $this->authenticatedUser($request);

        $project = ReferenceResolver::project($validated['reference']);

        if ($project === null || ! $user->can('view', $project)) {
            return Response::error('No project with short_name "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ".');
        }

        $parent = null;

        if (isset($validated['parent'])) {
            $parent = $this->resolveParentDoc($request, $validated['parent'], $project);

            if ($parent instanceof Response) {
                return $parent;
            }
        }

        $fetched = $project->docs()
            ->with('parent')
            // Drafts are editor-only, mirroring the doc policy as one query
            // instead of a per-row authorization check.
            ->unless($user->can('edit-doc', $project), static fn (Builder $query): Builder => $query->where('is_public', true))
            ->when($parent !== null, static fn (Builder $query): Builder => $query->where('parent_id', $parent->id))
            ->orderBy('position')
            ->orderBy('doc_number')
            ->when($limit !== null, static fn (Builder $query): Builder => $query->offset($offset)->limit($limit + 1))
            ->get();

        [$rows, $hasMore] = $this->sliceFetchedPage($fetched, $limit);

        $docs = $rows
            ->each(static fn (Doc $doc) => $doc->setRelation('project', $project))
            ->map(fn (Doc $doc): array => $this->docListPayload($doc))
            ->values();

        return Response::structured([
            'docs' => $docs->all(),
            'page' => $this->pageMeta($offset, $limit, $docs->count(), $hasMore),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The project short_name, 2-4 uppercase letters (e.g. "PROJ").')
                ->required(),

            'parent' => $schema->string()
                ->description('Optional parent doc reference (e.g. "PROJ-D3"); when given, only that doc\'s directly nested docs are returned.'),

            ...$this->pagingSchema($schema, 'docs'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'docs' => $schema->array()->items(
                $schema->object($this->docListSchema($schema))
            )->description('The accessible docs of the project, in their tree order.')->required(),
            'page' => $this->pageSchema($schema),
        ];
    }
}
