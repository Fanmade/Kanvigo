<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ExposesDependencies;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesDependencyPair;
use App\Models\Dependency;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Removes the dependency link between two stories or tasks, in whichever direction it exists. Requires a write-access token; the user must be a member of the project.')]
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
            'reference.required' => 'You must provide the reference of the story or task whose dependency you are removing (e.g. "PROJ1" or "PROJ1-3").',
            'related_reference.required' => 'You must provide the reference of the related story or task to unlink.',
        ]);

        $resolved = $this->resolveDependencyPair($request, $validated['reference'], $validated['related_reference']);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$item, $related] = $resolved;

        $deleted = Dependency::query()
            ->where(static fn (Builder $query): Builder => $query
                ->where('dependent_type', $item->getMorphClass())->where('dependent_id', $item->getKey())
                ->where('blocker_type', $related->getMorphClass())->where('blocker_id', $related->getKey()))
            ->orWhere(static fn (Builder $query): Builder => $query
                ->where('dependent_type', $related->getMorphClass())->where('dependent_id', $related->getKey())
                ->where('blocker_type', $item->getMorphClass())->where('blocker_id', $item->getKey()))
            ->delete();

        if ($deleted === 0) {
            return Response::error('No dependency exists between "'.$item->reference.'" and "'.$related->reference.'".');
        }

        $item->unsetRelation('dependencyLinks');
        $item->recordActivity('dependency_changed', 'dependencies');

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
                ->description('The reference of the story or task whose dependency you are removing (e.g. "PROJ1" or "PROJ1-3").')
                ->required(),

            'related_reference' => $schema->string()
                ->description('The reference of the related story or task to unlink (e.g. "PROJ1" or "PROJ1-3").')
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
            'related' => $schema->string()->description('The reference of the related story or task that was unlinked.')->required(),
            'blocked_by' => $schema->array()->items($schema->string())->description('References of the stories and tasks that still block the changed item.')->required(),
            'blocks' => $schema->array()->items($schema->string())->description('References of the stories and tasks that the changed item still blocks.')->required(),
            'is_blocked' => $schema->boolean()->description('Whether the changed item still has a blocker that is not yet complete.')->required(),
        ];
    }
}
