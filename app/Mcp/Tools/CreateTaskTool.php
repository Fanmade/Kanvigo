<?php

namespace App\Mcp\Tools;

use App\Actions\CreateTask;
use App\Enums\Priority;
use App\Enums\Status;
use App\Mcp\Concerns\NormalizesPlainText;
use App\Mcp\Concerns\PresentsTasks;
use App\Mcp\Concerns\RequiresWriteAccess;
use App\Mcp\Concerns\ResolvesTaskCreationReferences;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Creates a task in a project, identified by its short_name (e.g. "PROJ"). The task is top-level by default, or nested under a parent task when a "parent" task reference (e.g. "PROJ-42") is given. Requires a write-access token; the user must be a member of the project.')]
class CreateTaskTool extends Tool
{
    use NormalizesPlainText;
    use PresentsTasks;
    use RequiresWriteAccess;
    use ResolvesTaskCreationReferences;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        if ($denied = $this->denyWithoutWriteAccess($request)) {
            return $denied;
        }

        // A task may only be created in a working status — "Canceled" is a
        // terminal state reached through the cancel flow (which records a reason
        // and cascades to subtasks), never set directly on creation.
        $workingStatuses = array_map(static fn (Status $status): string => $status->value, Status::columns());
        $statuses = implode('", "', $workingStatuses);

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'parent' => ['nullable', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(Priority::names())],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in($workingStatuses)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ], [
            'reference.required' => 'You must provide the project short_name to add the task to (e.g. "PROJ").',
            'title.required' => 'You must provide a task title.',
            'priority' => 'The priority must be one of: '.implode(', ', Priority::names()).'.',
            'due_date' => 'The due date must be a calendar date in "YYYY-MM-DD" format.',
            'status' => 'The status must be one of "'.$statuses.'".',
        ]);

        $project = $this->resolveTaskProject($request, $validated['reference']);

        if ($project instanceof Response) {
            return $project;
        }

        $parent = $this->resolveParentTask($request, $validated['parent'] ?? null, $project);

        if ($parent instanceof Response) {
            return $parent;
        }

        try {
            $task = app(CreateTask::class)->handle(
                $project,
                $this->decodePlainText($validated['title']),
                $validated['description'] ?? null,
                isset($validated['priority']) ? Priority::fromName($validated['priority']) : null,
                isset($validated['status']) ? Status::from($validated['status']) : null,
                $validated['due_date'] ?? null,
                $parent,
            );
        } catch (InvalidArgumentException) {
            return Response::error('The task cannot be nested under "'.$validated['parent'].'": it would exceed the maximum nesting depth.');
        }

        $task->setRelation('project', $project);

        if (isset($validated['tags'])) {
            $task->syncTags($validated['tags']);
        }

        return Response::structured([
            ...$this->taskWritePayload($task),
            'parent' => $parent?->reference,
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
                ->description('The short_name of the project to add the task to (e.g. "PROJ").')
                ->required(),

            'parent' => $schema->string()
                ->description('Optional parent task reference (e.g. "PROJ-42") to nest the new task under, as a subtask. Must be a task in the same project, and the nesting must stay within the maximum depth. Omit for a top-level task.'),

            'title' => $schema->string()
                ->description('The task title.')
                ->required(),

            'description' => $schema->string()
                ->description('Optional task description, as HTML (sanitized to a small allow-list; unsupported tags are dropped).'),

            'priority' => $schema->string()
                ->enum(Priority::names())
                ->description('Optional priority: one of Lowest, Low, Medium, High, Highest. Defaults to the project default priority.'),

            'due_date' => $schema->string()
                ->description('Optional due date in "YYYY-MM-DD" format.'),

            'status' => $schema->string()
                ->enum(array_map(static fn (Status $status): string => $status->value, Status::columns()))
                ->description('Optional initial status, one of the working statuses (Planned, ToDo, In progress, Done). Defaults to "Planned". A task cannot be created already canceled — cancel it afterwards.'),

            'tags' => $schema->array()
                ->items($schema->string())
                ->description('Optional tags to apply, as an array of tag names (e.g. ["UI/UX", "bug"]). Tags that do not exist yet are created.'),
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
            ...$this->taskWriteSchema($schema),
            'parent' => $schema->string()->description('The parent task reference when the task was nested, otherwise null.'),
        ];
    }
}
