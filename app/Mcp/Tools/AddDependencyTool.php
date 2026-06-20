<?php

namespace App\Mcp\Tools;

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

#[Description('Links a dependency between two stories or tasks. With direction "blocked_by", the item at "reference" is blocked by "related_reference" and should not be started until it is complete. With direction "blocks", the item at "reference" blocks "related_reference". Self-dependencies and cycles are rejected. Requires a write-access token; the user must be a member of the project.')]
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
            'direction' => ['required', Rule::in(['blocked_by', 'blocks'])],
        ], [
            'reference.required' => 'You must provide the reference of the story or task whose dependencies you are changing (e.g. "PROJ1" or "PROJ-42").',
            'related_reference.required' => 'You must provide the reference of the related story or task to link.',
            'direction' => 'The direction must be "blocked_by" (reference is blocked by related_reference) or "blocks" (reference blocks related_reference).',
        ]);

        $resolution = $this->resolveDependencyPair($request, $validated['reference'], $validated['related_reference']);

        if ($resolution->failed()) {
            return $resolution->error();
        }

        [$item, $related] = $resolution->pair();

        // "blocked_by": the item depends on the related one. "blocks": the
        // related item depends on the item.
        [$dependent, $blocker] = $validated['direction'] === 'blocks'
            ? [$related, $item]
            : [$item, $related];

        try {
            $dependent->addBlocker($blocker);
        } catch (InvalidArgumentException) {
            return Response::error('That dependency would make an item depend on itself or create a cycle.');
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
                ->description('The reference of the story or task whose dependencies you are changing (e.g. "PROJ1" or "PROJ-42").')
                ->required(),

            'related_reference' => $schema->string()
                ->description('The reference of the related story or task to link (e.g. "PROJ1" or "PROJ-42").')
                ->required(),

            'direction' => $schema->string()
                ->enum(['blocked_by', 'blocks'])
                ->description('"blocked_by": reference is blocked by related_reference. "blocks": reference blocks related_reference.')
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
            'reference' => $schema->string()->description('The reference of the changed story or task.')->required(),
            'direction' => $schema->string()->description('The direction of the added link: "blocked_by" or "blocks".')->required(),
            'related' => $schema->string()->description('The reference of the related story or task that was linked.')->required(),
            'blocked_by' => $schema->array()->items($schema->string())->description('References of the stories and tasks that now block the changed item.')->required(),
            'blocks' => $schema->array()->items($schema->string())->description('References of the stories and tasks that the changed item now blocks.')->required(),
            'is_blocked' => $schema->boolean()->description('Whether the changed item has a blocker that is not yet complete.')->required(),
        ];
    }
}
