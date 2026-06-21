<?php

namespace App\Livewire\Tasks;

use App\Actions\CreateTask;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The single, globally-mounted dialog for creating a task. It is opened from any
 * page (board cards, the project overview, a parent task, the command palette) by
 * dispatching the `open-create-task` event with optional project/parent context.
 * Creation funnels through the shared {@see CreateTask} action; on success it
 * dispatches `task-created` so the originating page can refresh.
 */
class CreateTaskModal extends Component
{
    public bool $show = false;

    /**
     * Project/parent inferred from the page the component was mounted on, used to
     * preselect the dialog when it is opened without explicit context (e.g. from
     * the command palette). Captured at mount because a later Livewire request
     * runs on the update endpoint, where the page route is no longer available.
     */
    #[Locked]
    public ?int $contextProjectId = null;

    #[Locked]
    public ?int $contextParentId = null;

    public ?int $projectId = null;

    public ?int $parentId = null;

    public string $title = '';

    public string $description = '';

    public bool $showPreview = false;

    public int $priority;

    public string $status = '';

    public string $dueDate = '';

    /**
     * Tag names staged for the new task; created/attached after it is saved.
     *
     * @var array<int, string>
     */
    public array $tagNames = [];

    public string $tagQuery = '';

    /**
     * Ids of project members to assign to the new task.
     *
     * @var array<int, int>
     */
    public array $assigneeIds = [];

    public function mount(): void
    {
        [$this->contextProjectId, $this->contextParentId] = $this->routeContext();

        $this->resetForm();
    }

    /**
     * Open the dialog, preselecting the project and parent from the given context
     * or, when omitted, falling back to the page the component was mounted on.
     */
    #[On('open-create-task')]
    public function open(?int $projectId = null, ?int $parentId = null): void
    {
        $this->resetForm();

        $projectId ??= $this->contextProjectId;

        if ($parentId === null && $projectId === $this->contextProjectId) {
            $parentId = $this->contextParentId;
        }

        $this->projectId = $projectId;
        $this->parentId = $parentId;

        // A subtask inherits its parent's priority by default, matching how the
        // task page used to prefill the subtask form.
        if ($parentId !== null) {
            $parent = Task::find($parentId);

            if ($parent !== null) {
                $this->priority = $parent->priority->value;
            }
        }

        $this->show = true;
    }

    /**
     * The projects the authenticated user may create tasks in.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->projects()->orderBy('title')->get();
    }

    /**
     * Tasks in the selected project that may still take a child, as
     * `[id => "REF — title"]` options for the parent select.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parentOptions(): array
    {
        $project = $this->selectedProject();

        if ($project === null) {
            return [];
        }

        $maxDepth = (int) config('kanbrio.tasks.max_depth');

        $tasks = $project->tasks()
            ->select(['id', 'parent_id', 'task_number', 'title', 'status', 'archived_at'])
            ->get();

        $depthOf = $this->depthResolver($tasks->keyBy('id'));

        return $tasks
            ->reject(static fn (Task $task): bool => $task->isArchived())
            ->reject(static fn (Task $task): bool => $task->status->isTerminal())
            ->filter(static fn (Task $task): bool => $depthOf($task->id) < $maxDepth)
            ->mapWithKeys(fn (Task $task): array => [
                $task->id => $project->short_name.'-'.$task->task_number.' — '.$task->title,
            ])
            ->all();
    }

    /**
     * The members of the selected project, available for assignment.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        $project = $this->selectedProject();

        if ($project === null) {
            /** @var Collection<int, User> $empty */
            $empty = new Collection;

            return $empty;
        }

        return $project->members()->orderBy('name')->get();
    }

    /**
     * Up to eight most-used tags matching the current query and not already
     * staged, offered as suggestions in the tag input.
     *
     * @return BaseCollection<int, array{name: string, color: string}>
     */
    #[Computed]
    public function tagSuggestions(): BaseCollection
    {
        $query = trim($this->tagQuery);

        if ($query === '') {
            return new BaseCollection;
        }

        $applied = array_map(mb_strtolower(...), $this->tagNames);

        return Tag::query()
            ->select('tags.id', 'tags.name', 'tags.color')
            ->selectSub(
                DB::table('taggables')
                    ->selectRaw('count(*)')
                    ->whereColumn('taggables.tag_id', 'tags.id'),
                'usage_count'
            )
            ->whereLike('tags.name', '%'.$query.'%')
            ->orderByDesc('usage_count')
            ->orderBy('tags.name')
            ->limit(12)
            ->get()
            ->reject(static fn (Tag $tag): bool => in_array(mb_strtolower($tag->name), $applied, true))
            ->take(8)
            ->map(static fn (Tag $tag): array => ['name' => $tag->name, 'color' => $tag->color])
            ->values();
    }

    /**
     * Whether the typed query is a new tag name worth offering to create.
     */
    #[Computed]
    public function canCreateTag(): bool
    {
        $query = mb_strtolower(trim($this->tagQuery));

        if ($query === '') {
            return false;
        }

        if (in_array($query, array_map(mb_strtolower(...), $this->tagNames), true)) {
            return false;
        }

        return ! $this->tagSuggestions()->contains(static fn (array $tag): bool => mb_strtolower($tag['name']) === $query);
    }

    /**
     * Stage a suggested tag by its position in the suggestion list.
     */
    public function addSuggestedTag(int $index): void
    {
        $name = $this->tagSuggestions()->get($index)['name'] ?? null;

        if ($name !== null) {
            $this->stageTag($name);
        }
    }

    /**
     * Stage the typed query as a new tag.
     */
    public function createDraftTag(): void
    {
        $this->stageTag($this->tagQuery);
    }

    /**
     * Remove a staged tag by its position.
     */
    public function removeDraftTag(int $index): void
    {
        unset($this->tagNames[$index]);
        $this->tagNames = array_values($this->tagNames);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'projectId' => ['required', 'integer'],
            'parentId' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', new Enum(Priority::class)],
            'status' => ['required', 'string', 'in:'.collect(Status::columns())->map->value->implode(',')],
            'dueDate' => ['nullable', 'date'],
            'tagNames' => ['array'],
            'tagNames.*' => ['string', 'max:255'],
            'assigneeIds' => ['array'],
            'assigneeIds.*' => ['integer'],
        ]);

        $project = $this->projects()->firstWhere('id', $validated['projectId']);

        abort_if($project === null, 404);

        $this->authorize('update', $project);

        $parent = $this->resolveParent($project, $validated['parentId'] ?? null);

        $task = app(CreateTask::class)->handle(
            $project,
            $validated['title'],
            $validated['description'] ?: null,
            Priority::from($validated['priority']),
            Status::from($validated['status']),
            $validated['dueDate'] ?: null,
            $parent,
        );

        $this->applyTags($task);
        $this->applyAssignees($task, $project);

        $this->resetForm();
        $this->show = false;

        $this->dispatch('task-created');

        Flux::toast(variant: 'success', text: __('Task created.'));
    }

    /**
     * Re-scope the parent and member options whenever the chosen project changes,
     * dropping selections that no longer belong to it.
     */
    public function updatedProjectId(): void
    {
        unset($this->parentOptions, $this->members);
        $this->parentId = null;
        $this->assigneeIds = [];
    }

    /**
     * Reset the form to its defaults, ready for the next open.
     */
    protected function resetForm(): void
    {
        $this->reset('projectId', 'parentId', 'title', 'description', 'dueDate', 'showPreview', 'tagNames', 'tagQuery', 'assigneeIds');
        $this->priority = Priority::default()->value;
        $this->status = Status::Planned->value;
        unset($this->parentOptions, $this->members, $this->tagSuggestions);
    }

    /**
     * Stage a tag name, ignoring blanks and case-insensitive duplicates.
     */
    protected function stageTag(string $name): void
    {
        $name = trim($name);

        if ($name === '') {
            return;
        }

        $exists = collect($this->tagNames)->contains(
            static fn (string $staged): bool => mb_strtolower($staged) === mb_strtolower($name)
        );

        if (! $exists) {
            $this->tagNames[] = $name;
        }

        $this->reset('tagQuery');
        unset($this->tagSuggestions);
    }

    /**
     * Create (or reuse) the staged tags and attach them to the new task.
     */
    protected function applyTags(Task $task): void
    {
        if ($this->tagNames === []) {
            return;
        }

        $tagIds = collect($this->tagNames)
            ->map(static fn (string $name): int => Tag::firstOrCreate(['name' => trim($name)])->getKey())
            ->all();

        $task->tags()->syncWithoutDetaching($tagIds);
    }

    /**
     * Assign the chosen project members to the new task and subscribe them, the
     * same way assignment works on the task page.
     */
    protected function applyAssignees(Task $task, Project $project): void
    {
        $memberIds = $project->members()->pluck('users.id')->all();
        $assigneeIds = array_values(array_intersect($this->assigneeIds, $memberIds));

        if ($assigneeIds === []) {
            return;
        }

        $task->assignees()->sync($assigneeIds);
        $task->subscribers()->syncWithoutDetaching($assigneeIds);
        $task->recordAssigneeChange($assigneeIds, []);
    }

    /**
     * Infer project/parent context from the current page route: a project page
     * yields its project, a task page yields that task as the parent. Returns
     * nulls when the route carries no project the user can access.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function routeContext(): array
    {
        $shortName = request()->route('short_name');

        if (! is_string($shortName)) {
            return [null, null];
        }

        $project = $this->projects()->firstWhere('short_name', $shortName);

        if ($project === null) {
            return [null, null];
        }

        $taskNumber = request()->route('task_number');

        if (! is_string($taskNumber)) {
            return [$project->id, null];
        }

        $task = $project->tasks()->where('task_number', $taskNumber)->first();

        $parentId = $task !== null && $this->canParent($task) ? $task->id : null;

        return [$project->id, $parentId];
    }

    /**
     * Resolve and validate the chosen parent against the selected project.
     */
    protected function resolveParent(Project $project, ?int $parentId): ?Task
    {
        if ($parentId === null) {
            return null;
        }

        $parent = $project->tasks()->whereKey($parentId)->first();

        if ($parent === null || ! $this->canParent($parent)) {
            $this->addError('parentId', __('The selected parent task is not valid.'));
            abort(422);
        }

        return $parent;
    }

    /**
     * Whether the task may take another child within the depth limit.
     */
    protected function canParent(Task $task): bool
    {
        return $task->nestingDepth() < (int) config('kanbrio.tasks.max_depth');
    }

    protected function selectedProject(): ?Project
    {
        if ($this->projectId === null) {
            return null;
        }

        return $this->projects()->firstWhere('id', $this->projectId);
    }

    /**
     * Build a memoized depth resolver over a keyed task collection, counting the
     * root as level 1 by walking the in-memory parent chain (no extra queries).
     *
     * @param  Collection<int, Task>  $byId
     * @return callable(int): int
     */
    protected function depthResolver(Collection $byId): callable
    {
        $cache = [];

        $resolve = function (int $id) use (&$resolve, &$cache, $byId): int {
            if (isset($cache[$id])) {
                return $cache[$id];
            }

            $task = $byId->get($id);
            $parentId = $task?->parent_id;

            return $cache[$id] = $parentId === null ? 1 : $resolve($parentId) + 1;
        };

        return $resolve;
    }
}
