<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelTask;
use App\Actions\ChangeTaskStatus;
use App\Actions\CreateTask;
use App\Enums\CancelReason;
use App\Enums\Priority;
use App\Enums\Status;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiReferences;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Support\ReferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TaskController extends Controller
{
    use ResolvesApiReferences;

    /**
     * The relations the task resource serializes.
     *
     * @var list<string>
     */
    private const RESOURCE_RELATIONS = ['tags', 'project', 'parent', 'taskType', 'dependencyLinks.blocker'];

    /**
     * List a project's tasks, paginated, optionally filtered by status and/or
     * restricted to the direct subtasks of a parent task.
     */
    public function index(Request $request, string $short_name): AnonymousResourceCollection
    {
        $project = $this->resolveProjectOr404($short_name);

        $validated = $request->validate([
            'status' => ['nullable', new Enum(Status::class)],
            'parent' => ['nullable', 'string'],
        ]);

        $parentId = null;

        if (isset($validated['parent'])) {
            $parent = ReferenceResolver::task($validated['parent']);

            abort_if(
                $parent === null || $parent->project_id !== $project->id || Auth::user()->cannot('view', $parent),
                404,
            );

            $parentId = $parent->id;
        }

        $tasks = $project->tasks()
            ->with(['tags', 'project', 'taskType', 'dependencyLinks.blocker'])
            ->when(isset($validated['status']), fn ($query) => $query->where('status', Status::from($validated['status'])))
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('task_number')
            ->paginate();

        return TaskResource::collection($tasks);
    }

    /**
     * Create a task in a project. Optionally nest it under a parent task and set
     * an initial priority, status, due date, type and tags. Requires write access.
     */
    public function store(Request $request, string $short_name): JsonResponse
    {
        $project = $this->resolveProjectOr404($short_name);
        abort_if(Auth::user()->cannot('create-task', $project), 403);

        // A task may only be created in a working status — "Canceled" is a
        // terminal state reached through the cancel flow (which records a reason
        // and cascades to subtasks), never set directly on creation.
        $workingStatuses = array_map(static fn (Status $status): string => $status->value, Status::columns());

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(Priority::names())],
            'status' => ['nullable', Rule::in($workingStatuses)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'parent' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ]);

        $parent = null;

        if (isset($validated['parent'])) {
            $parent = ReferenceResolver::task($validated['parent']);

            abort_if(
                $parent === null || $parent->project_id !== $project->id || Auth::user()->cannot('view', $parent),
                404,
            );
        }

        $type = $this->resolveType($project, $validated['type'] ?? null);

        try {
            $task = app(CreateTask::class)->handle(
                $project,
                $validated['title'],
                $validated['description'] ?? null,
                isset($validated['priority']) ? Priority::fromName($validated['priority']) : null,
                isset($validated['status']) ? Status::from($validated['status']) : null,
                $validated['due_date'] ?? null,
                $parent,
                $type,
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'parent' => __('The task cannot be nested there: it would exceed the maximum nesting depth.'),
            ]);
        }

        if (isset($validated['tags'])) {
            $task->syncTags($validated['tags']);
        }

        $task->loadMissing(self::RESOURCE_RELATIONS);

        return TaskResource::make($task)->response()->setStatusCode(201);
    }

    /**
     * Update a task's fields. Status changes route through the shared cascade
     * action; tags are synced and logged. Requires write access.
     */
    public function update(Request $request, string $reference): TaskResource
    {
        $task = $this->resolveTaskOr404($reference, 'update');

        $workingStatuses = array_map(static fn (Status $status): string => $status->value, Status::columns());

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(Priority::names())],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in($workingStatuses)],
            'type' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ]);

        if ($task->isCanceled() && $request->has('status')) {
            throw ValidationException::withMessages([
                'status' => __('This task is canceled and cannot change status. Reopen it first.'),
            ]);
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

        if ($updates !== []) {
            $task->update($updates);
        }

        if ($request->has('type')) {
            $task->task_type_id = $this->resolveType($task->project, $validated['type'] ?? null)?->getKey();
            $task->save();
        }

        if ($request->has('status') && isset($validated['status'])) {
            $new = Status::from($validated['status']);

            if ($task->status !== $new) {
                app(ChangeTaskStatus::class)->handle($task, $new);
            }
        }

        if ($request->has('tags')) {
            $task->recordTagSync($task->syncTags($validated['tags'] ?? []));
        }

        $task->refresh()->loadMissing(self::RESOURCE_RELATIONS);

        return new TaskResource($task);
    }

    /**
     * Show a single task by its reference (e.g. "PROJ-42"), with its full detail —
     * assignees, dependencies, subtasks and attachments. 404s when it does not
     * exist or belongs to a project the user cannot see.
     */
    public function show(string $reference): TaskDetailResource
    {
        $task = $this->resolveTaskOr404($reference);

        return $this->detail($task);
    }

    /**
     * Cancel a task (and its open subtree) with a reason and optional message.
     */
    public function cancel(Request $request, string $reference): TaskDetailResource
    {
        $task = $this->resolveTaskOr404($reference, 'cancel');

        if ($task->isCanceled()) {
            throw ValidationException::withMessages(['cancel_reason' => __('This task is already canceled.')]);
        }

        $validated = $request->validate([
            'cancel_reason' => ['required', Rule::in(CancelReason::names())],
            'cancel_message' => ['nullable', 'string', 'max:1000'],
        ]);

        app(CancelTask::class)->cancel(
            $task,
            CancelReason::fromName($validated['cancel_reason']),
            $validated['cancel_message'] ?? null,
        );

        return $this->detail($task->fresh());
    }

    /**
     * Reopen a canceled task, returning it to Planned.
     */
    public function reopen(string $reference): TaskDetailResource
    {
        $task = $this->resolveTaskOr404($reference, 'cancel');

        if (! $task->isCanceled()) {
            throw ValidationException::withMessages(['status' => __('This task is not canceled.')]);
        }

        app(CancelTask::class)->reopen($task);

        return $this->detail($task->fresh());
    }

    /**
     * Replace a task's assignees with the given project members, referenced by
     * their stable public id (the "id" the task resource reports per assignee).
     * Mirrors the web/MCP behaviour: assigning a user auto-subscribes them, and the
     * change is logged. Ids that are not project members are ignored.
     */
    public function setAssignees(Request $request, string $reference): TaskDetailResource
    {
        $task = $this->resolveTaskOr404($reference, 'update');

        $validated = $request->validate([
            'assignee_ids' => ['present', 'array'],
            'assignee_ids.*' => ['string'],
        ]);

        $assigneeIds = $task->project->members()
            ->whereIn('users.public_id', $validated['assignee_ids'])
            ->pluck('users.id')
            ->all();

        $changes = $task->assignees()->sync($assigneeIds);

        if ($changes['attached'] !== []) {
            $task->subscribers()->syncWithoutDetaching($changes['attached']);
        }

        $task->recordAssigneeChange($changes['attached'], $changes['detached']);

        return $this->detail($task->fresh());
    }

    /**
     * Eager-load a task's full detail relations and wrap it in the detail resource.
     */
    private function detail(Task $task): TaskDetailResource
    {
        $task->loadMissing([
            'tags', 'project', 'parent', 'taskType', 'children', 'assignees', 'attachments',
            ...Task::dependencyTargetsEagerLoad(),
        ]);

        return new TaskDetailResource($task);
    }

    /**
     * Resolve a task-type name (case-insensitive) to one of the project's types,
     * or null when no name is given. An unknown name is a validation error.
     */
    private function resolveType(Project $project, ?string $name): ?TaskType
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $type = $project->taskTypes()
            ->whereNameLower(trim($name))
            ->first();

        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => __('No task type named ":name" exists in this project.', ['name' => $name]),
            ]);
        }

        return $type;
    }
}
