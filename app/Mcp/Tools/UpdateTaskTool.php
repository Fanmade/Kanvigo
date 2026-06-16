<?php

namespace App\Mcp\Tools;

use App\Enums\Status;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Updates a task\'s title, description and/or status, identified by its reference (e.g. "PROJ1-3"). Status changes are recorded in the activity log. Requires a write-access token; the user must be a member of the project.')]
class UpdateTaskTool extends Tool
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

        $statuses = implode('", "', array_map(static fn (Status $status): string => $status->value, Status::cases()));

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', new Enum(Status::class)],
        ], [
            'reference.required' => 'You must provide the task reference (e.g. "PROJ1-3").',
            'status' => 'The status must be one of "'.$statuses.'".',
        ]);

        $task = ReferenceResolver::task($validated['reference']);

        if ($task === null || ! $request->user()->can('update', $task)) {
            return Response::error('No task with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ1-3".');
        }

        $updates = [];

        if ($request->has('title')) {
            $updates['title'] = $validated['title'];
        }

        if ($request->has('description')) {
            $updates['description'] = $validated['description'];
        }

        $statusProvided = $request->has('status') && isset($validated['status']);

        if ($updates === [] && ! $statusProvided) {
            return Response::error('Provide a title, description and/or status to update.');
        }

        if ($updates !== []) {
            $task->update($updates);
        }

        if ($statusProvided) {
            $new = Status::from($validated['status']);

            if ($task->status !== $new) {
                $old = $task->status;
                $task->status = $new;
                $task->save();

                $task->recordActivity('status_changed', 'status', $old->value, $new->value);
            }
        }

        return Response::structured([
            'reference' => $task->reference,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
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
                ->description('The reference of the task to update (e.g. "PROJ1-3").')
                ->required(),

            'title' => $schema->string()
                ->description('New title for the task.'),

            'description' => $schema->string()
                ->description('New description for the task.'),

            'status' => $schema->string()
                ->enum(array_map(static fn (Status $status): string => $status->value, Status::cases()))
                ->description('New status for the task.'),
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
            'reference' => $schema->string()->description('The task reference, e.g. "PROJ1-3".')->required(),
            'title' => $schema->string()->description('The updated task title.')->required(),
            'description' => $schema->string()->description('The updated task description; may be null.'),
            'status' => $schema->string()->description('The task status.')->required(),
        ];
    }
}
