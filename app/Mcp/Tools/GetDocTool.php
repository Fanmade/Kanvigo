<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\PresentsDocs;
use App\Mcp\Concerns\ResolvesDocReferences;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets a single reference doc by its reference (e.g. "PROJ-D3"), including its HTML body, the docs nested under it, its tags, attachments and the tasks and docs it links to (and that link back to it). A draft doc is only accessible to members who may edit docs.')]
#[IsReadOnly]
class GetDocTool extends Tool
{
    use PresentsDocs;
    use ResolvesDocReferences;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'reference' => ['required', 'string'],
        ], [
            'reference.required' => 'You must provide the doc reference, formed from the project short name, "-D" and the doc number (e.g. "PROJ-D3").',
        ]);

        $doc = $this->resolveDoc($request, $validated['reference']);

        if ($doc instanceof Response) {
            return $doc;
        }

        return Response::structured($this->docPayload($doc, $this->authenticatedUser($request)));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The doc reference: the project short name, "-D" and the doc number (e.g. "PROJ-D3").')
                ->required(),
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
