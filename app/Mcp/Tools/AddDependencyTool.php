<?php

namespace App\Mcp\Tools;

use App\Enums\RelationshipType;
use App\Mcp\Concerns\ExposesDependencies;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesDependencyPair;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Links a typed relationship between two tasks. The "relationship" reads from "reference" to "related_reference": "blocked_by" (reference is blocked by related_reference and should not start until it is complete), "blocks", "relates" (a non-directional link), "duplicates"/"duplicated_by", "clones"/"cloned_by", "causes"/"caused_by". Only "blocks"/"blocked_by" affect whether a task is blocked. Self-links and blocking cycles are rejected. Requires a write-access token; the user must be a member of the project.')]
class AddDependencyTool extends Tool
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
            'direction' => ['required', Rule::in(RelationshipType::keywords())],
        ], [
            'reference.required' => 'You must provide the reference of the task whose relationships you are changing (e.g. "PROJ-42").',
            'related_reference.required' => 'You must provide the reference of the related task to link.',
            'direction' => 'The relationship must be one of: '.implode(', ', RelationshipType::keywords()).'.',
        ]);

        $resolution = $this->resolveDependencyPair($request, $validated['reference'], $validated['related_reference']);

        if ($resolution->failed()) {
            return $resolution->error();
        }

        [$item, $related] = $resolution->pair();

        [$type, $asSubject] = RelationshipType::requireKeyword($validated['direction']);

        try {
            $item->addRelationship($related, $type, $asSubject);
        } catch (InvalidArgumentException) {
            return Response::error('That relationship would link an item to itself or create a blocking cycle.');
        }

        $item->recordDependencyChange(true, $validated['direction'], $related->reference);

        return Response::structured([
            'reference' => $item->reference,
            'direction' => $validated['direction'],
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
                ->description('The reference of the task whose dependencies you are changing (e.g. "PROJ-42").')
                ->required(),

            'related_reference' => $schema->string()
                ->description('The reference of the related task to link (e.g. "PROJ-42").')
                ->required(),

            'direction' => $schema->string()
                ->enum(RelationshipType::keywords())
                ->description('The relationship from "reference" to "related_reference": blocked_by, blocks, relates, duplicates, duplicated_by, clones, cloned_by, causes, caused_by.')
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
            'direction' => $schema->string()->description('The relationship keyword that was added.')->required(),
            'related' => $schema->string()->description('The reference of the related task that was linked.')->required(),
            ...$this->dependencySchema($schema),
        ];
    }
}
