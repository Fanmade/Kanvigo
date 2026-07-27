<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\NormalizesPlainText;
use App\Mcp\Concerns\PresentsDocs;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesDocReferences;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a reference doc in a project, identified by its short_name (e.g. "PROJ"). The doc is top-level by default, or nested under a "parent" doc reference (e.g. "PROJ-D3"). A new doc is a draft (only members who may edit docs can see it) unless "public" is true. Requires a write-access token and the create-doc permission in the project.')]
class CreateDocTool extends Tool
{
    use NormalizesPlainText;
    use PresentsDocs;
    use RequiresWriteAccess;
    use ResolvesDocReferences;

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'parent' => ['nullable', 'string'],
            'public' => ['nullable', 'boolean'],
        ], [
            'reference.required' => 'You must provide the project short_name to add the doc to (e.g. "PROJ").',
            'title.required' => 'You must provide a doc title.',
        ]);

        $project = $this->resolveDocProject($request, $validated['reference']);

        if ($project instanceof Response) {
            return $project;
        }

        $parent = $this->resolveParentDoc($request, $validated['parent'] ?? null, $project);

        if ($parent instanceof Response) {
            return $parent;
        }

        // The model rejects a parent that would nest the doc past the depth limit.
        try {
            $doc = $project->docs()->create([
                'title' => $this->decodePlainText($validated['title']),
                'body' => $validated['body'] ?? null,
                'parent_id' => $parent?->getKey(),
                'is_public' => (bool) ($validated['public'] ?? false),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        $doc->setRelation('project', $project);
        $doc->setRelation('parent', $parent);

        return Response::structured($this->docPayload($doc, $this->authenticatedUser($request)));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The short_name of the project to create the doc in (e.g. "PROJ").')
                ->required(),

            'title' => $schema->string()
                ->description('The doc title.')
                ->required(),

            'body' => $schema->string()
                ->description('Optional doc body, as HTML (sanitized to a small allow-list; unsupported tags are dropped).'),

            'parent' => $schema->string()
                ->description('Optional parent doc reference (e.g. "PROJ-D3") to nest the new doc under. Must be a doc in the same project.'),

            'public' => $schema->boolean()
                ->description('Whether the doc is published to the project straight away. Defaults to false — a draft only members who may edit docs can see.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->docSchema($schema);
    }
}
