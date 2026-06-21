<?php

namespace App\Mcp\Tools;

use App\Enums\Status;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists the tasks of a project, identified by its short_name (e.g. "PROJ"), optionally filtered by status and/or restricted to the direct subtasks of a "parent" task (e.g. "PROJ-42"). Each task reports its own parent, so the nesting can be reconstructed. Only projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class ListTasksTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $statuses = implode('", "', array_map(static fn (Status $status): string => $status->value, Status::cases()));

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'parent' => ['nullable', 'string'],
            'status' => ['nullable', new Enum(Status::class)],
        ], [
            'reference.required' => 'You must provide the project short_name (e.g. "PROJ").',
            'status' => 'The status filter must be one of "'.$statuses.'".',
        ]);

        $project = ReferenceResolver::project($validated['reference']);

        if ($project === null || ! $request->user()->can('view', $project)) {
            return Response::error('No project with short_name "'.$validated['reference'].'" exists, or you do not have access to it. References look like "PROJ".');
        }

        $parent = null;

        if (isset($validated['parent'])) {
            $parent = ReferenceResolver::task($validated['parent']);

            if ($parent === null || $parent->project_id !== $project->id || ! $request->user()->can('view', $parent)) {
                return Response::error('No task with reference "'.$validated['parent'].'" exists in project "'.$project->short_name.'", or you do not have access to it.');
            }
        }

        $project->loadMissing([
            'tasks.tags',
            'tasks.project',
            // Eager-load each task's blockers so isBlocked() stays N+1-free.
            'tasks.dependencyLinks.blocker',
        ]);

        $shortName = $project->short_name;
        $numbersById = $project->tasks->pluck('task_number', 'id');

        $tasks = $project->tasks
            ->when(
                $parent !== null,
                static fn ($tasks) => $tasks->where('parent_id', $parent->id)
            )
            ->when(
                isset($validated['status']),
                static fn ($tasks) => $tasks->where('status', Status::from($validated['status']))
            )
            ->map(static fn (Task $task): array => [
                'reference' => $task->reference,
                'parent' => $task->parent_id !== null && $numbersById->has($task->parent_id)
                    ? $shortName.'-'.$numbersById[$task->parent_id]
                    : null,
                'title' => $task->title,
                'priority' => $task->priority->name,
                'due_date' => $task->due_date?->format('Y-m-d'),
                'status' => $task->status->value,
                'cancel_reason' => $task->cancel_reason?->name,
                'tags' => $task->tags->pluck('name')->all(),
                'is_blocked' => $task->isBlocked(),
            ])
            ->values();

        return Response::structured([
            'tasks' => $tasks->all(),
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
                ->description('The project short_name, 2-4 uppercase letters (e.g. "PROJ").')
                ->required(),

            'parent' => $schema->string()
                ->description('Optional parent task reference (e.g. "PROJ-42"); when given, only that task\'s direct subtasks are returned.'),

            'status' => $schema->string()
                ->enum(array_map(static fn (Status $status): string => $status->value, Status::cases()))
                ->description('Optional status filter. One of the task statuses.'),
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
            'tasks' => $schema->array()->items($schema->object([
                'reference' => $schema->string()->description('The task reference, e.g. "PROJ-42".')->required(),
                'parent' => $schema->string()->description('The parent task reference (e.g. "PROJ-42"), or null when this is a top-level task.'),
                'title' => $schema->string()->description('The task title.')->required(),
                'priority' => $schema->string()->description('The task priority: Lowest, Low, Medium, High or Highest.')->required(),
                'due_date' => $schema->string()->description('The task due date in "YYYY-MM-DD" format; may be null.'),
                'status' => $schema->string()->description('The task status.')->required(),
                'cancel_reason' => $schema->string()->description('Why the task was canceled (WontFix, Duplicate or Deprecated) when its status is Canceled; null otherwise. Use the get-task tool for the cancellation message.'),
                'tags' => $schema->array()->items($schema->string())->description('The tag names applied to the task.')->required(),
                'is_blocked' => $schema->boolean()->description('Whether the task has a blocker that is not yet complete. Use the get-task tool for the specific blocking/blocked references.')->required(),
            ]))->description('The tasks in the project.')->required(),
        ];
    }
}
