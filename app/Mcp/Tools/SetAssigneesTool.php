<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresWriteAccess;
use App\Models\User;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Replaces a task\'s assignees with the given set of users, referenced by their stable user id (the "id" reported on get-task assignees). The set is absolute: pass every id that should be assigned, or an empty list to clear. Only members of the task\'s project can be assigned — other ids are ignored. Assigning a user subscribes them to the task. Requires a write-access token; the user must be able to edit the task.')]
class SetAssigneesTool extends Tool
{
    use RequiresWriteAccess;

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
            'assignee_ids' => ['present', 'array'],
            'assignee_ids.*' => ['string'],
        ], [
            'reference.required' => 'You must provide the task reference (e.g. "PROJ-42").',
            'assignee_ids.present' => 'You must provide the assignee_ids array (empty to clear all assignees).',
        ]);

        $task = ReferenceResolver::task($validated['reference']);

        if ($task === null || ! $request->user()->can('update', $task)) {
            return Response::error('No task with reference "'.$validated['reference'].'" exists, or you cannot edit it.');
        }

        $assigneeIds = $task->project->members()
            ->whereIn('users.public_id', $validated['assignee_ids'])
            ->pluck('users.id')
            ->all();

        $changes = $task->assignees()->sync($assigneeIds);

        if ($changes['attached'] !== []) {
            $task->subscribers()->syncWithoutDetaching($changes['attached']);
        }

        $task->recordAssigneeChange($changes['attached'], $changes['detached']);

        return Response::structured([
            'reference' => $task->reference,
            'assignees' => $task->assignees()->get()->map(static fn (User $user): array => [
                'id' => $user->public_id,
                'name' => $user->name,
            ])->all(),
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
                ->description('The reference of the task whose assignees you are setting (e.g. "PROJ-42").')
                ->required(),

            'assignee_ids' => $schema->array()->items($schema->string())
                ->description('The complete set of user ids to assign (the "id" from get-task assignees or get-user). Replaces the current assignees; pass [] to clear. Ids that are not members of the task\'s project are ignored.')
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
            'reference' => $schema->string()->description('The task reference.')->required(),
            'assignees' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('The assignee\'s stable user id.')->required(),
                'name' => $schema->string()->description('The assignee name.')->required(),
            ]))->description('The task\'s assignees after the change.')->required(),
        ];
    }
}
