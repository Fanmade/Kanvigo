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

#[Description('Updates a reference doc by its reference (e.g. "PROJ-D3"). Any of title, body, the parent doc and the published flag may be changed; omitted fields are left as-is. Pass "parent" as a doc reference to nest this doc under it, or as an empty value to move it to the top level. Setting "public" to true publishes the doc to the project, false returns it to a draft. Requires a write-access token and the edit-doc permission in the project.')]
class UpdateDocTool extends Tool
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'parent' => ['nullable', 'string'],
            'public' => ['nullable', 'boolean'],
        ], [
            'reference.required' => 'You must provide the doc reference (e.g. "PROJ-D3").',
            'title.required' => 'The doc title cannot be empty.',
        ]);

        $doc = $this->resolveDoc($request, $validated['reference'], 'update');

        if ($doc instanceof Response) {
            return $doc;
        }

        $changes = [];

        if ($request->has('title')) {
            $changes['title'] = $this->decodePlainText($validated['title']);
        }

        if ($request->has('body')) {
            $changes['body'] = $validated['body'] ?? null;
        }

        if ($request->has('public')) {
            $changes['is_public'] = (bool) ($validated['public'] ?? false);
        }

        if ($request->has('parent')) {
            $parent = $this->resolveParentDoc($request, $validated['parent'] ?? null, $doc->project);

            if ($parent instanceof Response) {
                return $parent;
            }

            $changes['parent_id'] = $parent?->getKey();
        }

        // The model rejects a parent that closes a cycle or nests too deep.
        try {
            $doc->update($changes);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        $doc->unsetRelation('parent');

        return Response::structured($this->docPayload($doc, $this->authenticatedUser($request)));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The reference of the doc to update (e.g. "PROJ-D3").')
                ->required(),

            'title' => $schema->string()
                ->description('A new title. Omit to leave unchanged.'),

            'body' => $schema->string()
                ->description('A new body, as HTML (sanitized). Pass an empty value to clear it; omit to leave unchanged.'),

            'parent' => $schema->string()
                ->description('A doc reference (e.g. "PROJ-D3") to nest this doc under, or an empty value to move it to the top level. Must be a doc in the same project, and may not be this doc or one nested under it. Omit to leave unchanged.'),

            'public' => $schema->boolean()
                ->description('Whether the doc is published to the project (false returns it to a draft). Omit to leave unchanged.'),
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
