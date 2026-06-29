<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ExposesDependencies;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesDependencyPair;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Removes the relationship link between two tasks, of whatever type and in whichever direction it exists. Requires a write-access token; the user must be a member of the project.')]
class RemoveDependencyTool extends Tool
{
    use ExposesDependencies;
    use RequiresWriteAccess;
    use ResolvesDependencyPair;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'related_reference' => ['required', 'string'],
        ], [
            'reference.required' => 'You must provide the reference of the task whose dependency you are removing (e.g. "PROJ-42").',
            'related_reference.required' => 'You must provide the reference of the related task to unlink.',
        ]);

        $resolution = $this->resolveDependencyPair($request, $validated['reference'], $validated['related_reference']);

        if ($resolution->failed()) {
            return $resolution->error();
        }

        [$item, $related] = $resolution->pair();

        if ($item->removeRelationshipWith($related) === null) {
            return Response::error('No dependency exists between "'.$item->reference.'" and "'.$related->reference.'".');
        }

        return Response::structured([
            'reference' => $item->reference,
            'related' => $related->reference,
            ...$this->dependencyPayload($item),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()
                ->description('The reference of the task whose dependency you are removing (e.g. "PROJ-42").')
                ->required(),

            'related_reference' => $schema->string()
                ->description('The reference of the related task to unlink (e.g. "PROJ-42").')
                ->required(),
        ];
    }

    /**
     * Get the tool's output schema.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The reference of the changed task.')->required(),
            'related' => $schema->string()->description('The reference of the related task that was unlinked.')->required(),
            ...$this->dependencySchema($schema),
        ];
    }
}
