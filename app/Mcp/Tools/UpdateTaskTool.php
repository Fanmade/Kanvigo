<?php

namespace App\Mcp\Tools;

use App\Enums\Priority;
use App\Enums\Status;
use App\Mcp\Concerns\RecordsTagChanges;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Updates a task\'s title, description, priority and/or status, identified by its reference (e.g. "PROJ-42"). Status changes are recorded in the activity log. Requires a write-access token; the user must be a member of the project.')]
class UpdateTaskTool extends Tool
{
    use RecordsTagChanges;
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
            'priority' => ['nullable', Rule::in(Priority::names())],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', new Enum(Status::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ], [
            'reference.required' => 'You must provide the task reference (e.g. "PROJ-42").',
            'priority' => 'The priority must be one of: '.implode(', ', Priority::names()).'.',
            'due_date' => 'The due date must be a calendar date in "YYYY-MM-DD" format. Pass null to clear it.',
            'status' => 'The status must be one of "'.$statuses.'".',
        ]);

        $task = ReferenceResolver::task($validated['reference']);

        if ($task === null || ! $request->user()->can('update', $task)) {
            return Response::error('No task with reference "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ-42".');
        }

        $updates = [];

        if ($request->has('title')) {
            $updates['title'] = $validated['title'];
        }

        if ($request->has('description')) {
            $updates['description'] = $validated['description'];
        }

        if ($request->has('priority') && isset($validated['priority'])) {
            $updates['priority'] = Priority::fromName($validated['priority']);
        }

        if ($request->has('due_date')) {
            $updates['due_date'] = $validated['due_date'];
        }

        $statusProvided = $request->has('status') && isset($validated['status']);
        $tagsProvided = $request->has('tags');

        if ($updates === [] && ! $statusProvided && ! $tagsProvided) {
            return Response::error('Provide a title, description, priority, due date, status and/or tags to update.');
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

        if ($tagsProvided) {
            $this->recordTagSync($task, $task->syncTags($validated['tags'] ?? []));
        }

        return Response::structured([
            'reference' => $task->reference,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority->name,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'status' => $task->status->value,
            'tags' => $task->tags()->pluck('name')->all(),
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
                ->description('The reference of the task to update (e.g. "PROJ-42").')
                ->required(),

            'title' => $schema->string()
                ->description('New title for the task.'),

            'description' => $schema->string()
                ->description('New description for the task.'),

            'priority' => $schema->string()
                ->enum(Priority::names())
                ->description('New priority: one of Lowest, Low, Medium, High, Highest.'),

            'due_date' => $schema->string()
                ->description('New due date in "YYYY-MM-DD" format. Pass null to clear it.'),

            'status' => $schema->string()
                ->enum(array_map(static fn (Status $status): string => $status->value, Status::cases()))
                ->description('New status for the task.'),

            'tags' => $schema->array()
                ->items($schema->string())
                ->description('The complete set of tags for the task, as an array of tag names (e.g. ["UI/UX", "bug"]). Replaces the existing tags; pass [] to clear them. Tags that do not exist yet are created.'),
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
            'reference' => $schema->string()->description('The task reference, e.g. "PROJ-42".')->required(),
            'title' => $schema->string()->description('The updated task title.')->required(),
            'description' => $schema->string()->description('The updated task description; may be null.'),
            'priority' => $schema->string()->description('The task priority: Lowest, Low, Medium, High or Highest.')->required(),
            'due_date' => $schema->string()->description('The task due date in "YYYY-MM-DD" format; may be null.'),
            'status' => $schema->string()->description('The task status.')->required(),
            'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the task.')->required(),
        ];
    }
}
