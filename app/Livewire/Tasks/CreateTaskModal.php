<?php

namespace App\Livewire\Tasks;

use App\Actions\ConvertNote;
use App\Actions\CreateTask;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Note;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The single, globally mounted dialog for creating a task. It is opened from any
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

    /**
     * When converting a note into a task: the source note, linked to the new
     * task on save so its "Converted → …" badge points back at it.
     */
    public ?int $fromNoteId = null;

    public string $title = '';

    public string $description = '';

    /**
     * When on, the dialog stays open after saving so another task can be created.
     */
    public bool $createAnother = false;

    public int $priority;

    public string $status = '';

    /**
     * The chosen task type, or null for an untyped task. Scoped to the selected
     * project's configured types.
     */
    public ?int $typeId = null;

    public string $dueDate = '';

    /**
     * Tag names staged for the new task; created/attached after it is saved.
     *
     * @var array<int, string>
     */
    public array $tagNames = [];

    /**
     * Display/creation color per staged tag name (an existing tag's color, or the
     * one chosen for a brand-new tag).
     *
     * @var array<string, string>
     */
    public array $tagColors = [];

    public string $tagQuery = '';

    // Create-tag (color picker) sub-dialog state.
    public bool $showTagColorModal = false;

    public string $newTagName = '';

    public string $newTagColor = 'zinc';

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
    public function open(?int $projectId = null, ?int $parentId = null, ?int $fromNoteId = null): void
    {
        $this->resetForm();

        // Converting a note: prefill the title/body from it and default the
        // project to the note's, if attached. Only the owner may convert.
        if ($fromNoteId !== null) {
            $note = Note::findOrFail($fromNoteId);
            $this->authorize('update', $note);

            $this->fromNoteId = $note->id;
            $this->title = $note->title;
            $this->description = (string) $note->body;
            $projectId ??= $note->project_id;
        }

        $projectId ??= $this->contextProjectId;

        // With a single available project there is nothing to choose, so preselect
        // it (the dropdown is hidden in that case).
        if ($projectId === null && $this->projects()->count() === 1) {
            $projectId = $this->projects()->first()?->id;
        }

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

        $maxDepth = (int) config('kanvigo.tasks.max_depth');

        $tasks = $project->tasks()
            ->select(['id', 'parent_id', 'task_number', 'title', 'status', 'archived_at'])
            ->get();

        $depthOf = $this->depthResolver($tasks->keyBy('id'));

        $label = static fn (Task $task): string => $project->short_name.'-'.$task->task_number.' — '.$task->title;

        $options = $tasks
            ->reject(static fn (Task $task): bool => $task->isArchived())
            ->reject(static fn (Task $task): bool => $task->status->isTerminal())
            ->filter(static fn (Task $task): bool => $depthOf($task->id) < $maxDepth)
            ->mapWithKeys(static fn (Task $task): array => [$task->id => $label($task)])
            ->all();

        // Always keep the contextually preselected parent in the list (e.g. a
        // subtask added from a Done task page) so the select has an option for its
        // value, even when the filters above would otherwise drop it.
        if ($this->parentId !== null && ! array_key_exists($this->parentId, $options)) {
            $selected = $tasks->firstWhere('id', $this->parentId);

            if ($selected !== null) {
                $options = [$selected->id => $label($selected)] + $options;
            }
        }

        return $options;
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
     * The selected project's configured task types, offered in the type picker.
     *
     * @return Collection<int, TaskType>
     */
    #[Computed]
    public function taskTypes(): Collection
    {
        $project = $this->selectedProject();

        if ($project === null) {
            /** @var Collection<int, TaskType> $empty */
            $empty = new Collection;

            return $empty;
        }

        return $project->taskTypes()->get();
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

        if ($query === '' || $this->projectId === null) {
            return new BaseCollection;
        }

        $applied = array_map(mb_strtolower(...), $this->tagNames);

        return Tag::query()
            ->where('tags.project_id', $this->projectId)
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
     * Stage a suggested (existing) tag by its position in the suggestion list,
     * keeping its established color.
     */
    public function addSuggestedTag(int $index): void
    {
        $suggestion = $this->tagSuggestions()->get($index);

        if ($suggestion !== null) {
            $this->stageTag($suggestion['name'], $suggestion['color']);
        }
    }

    /**
     * Handle Enter in the tag input: stage an exact existing match outright, or
     * open the color picker for a brand-new tag.
     */
    public function tagEnter(string $value): void
    {
        $this->openTagColorModal($value);
    }

    /**
     * Begin creating a new tag: stage an exact existing match directly, otherwise
     * open the color picker prefilled with a name-derived default color.
     */
    public function openTagColorModal(?string $name = null): void
    {
        $name = trim($name ?? $this->tagQuery);

        if ($name === '') {
            return;
        }

        $existing = Tag::query()
            ->where('project_id', $this->projectId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            $this->stageTag($existing->name, $existing->color);
            $this->reset('tagQuery');
            unset($this->tagSuggestions);

            return;
        }

        $this->resetErrorBag('newTagName');
        $this->newTagName = $name;
        $this->newTagColor = Tag::colorForName($name);
        $this->showTagColorModal = true;
    }

    /**
     * Confirm the new tag from the color picker and stage it with its color.
     */
    public function confirmNewTag(): void
    {
        $validated = $this->validate([
            'newTagName' => ['required', 'string', 'max:255'],
            'newTagColor' => ['required', 'string', 'in:'.implode(',', Tag::PALETTE)],
        ]);

        $this->stageTag($validated['newTagName'], $validated['newTagColor']);

        $this->reset('newTagName', 'tagQuery', 'showTagColorModal');
        $this->newTagColor = 'zinc';
        unset($this->tagSuggestions);
    }

    /**
     * Remove a staged tag by its position.
     */
    public function removeDraftTag(int $index): void
    {
        $name = $this->tagNames[$index] ?? null;

        unset($this->tagNames[$index]);
        $this->tagNames = array_values($this->tagNames);

        if ($name !== null) {
            unset($this->tagColors[$name]);
        }
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
            'typeId' => ['nullable', 'integer', Rule::exists('task_types', 'id')->where('project_id', $this->projectId)],
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

        $type = $validated['typeId'] !== null
            ? $project->taskTypes()->whereKey($validated['typeId'])->first()
            : null;

        $task = app(CreateTask::class)->handle(
            $project,
            $validated['title'],
            $validated['description'] ?: null,
            Priority::from($validated['priority']),
            Status::from($validated['status']),
            $validated['dueDate'] ?: null,
            $parent,
            $type,
        );

        $this->applyTags($task);
        $this->applyAssignees($task, $project);

        // When this task was created by converting a note, link them back so the
        // note keeps a "Converted → …" badge. Skipped if the note has since gone
        // or the user can no longer edit it.
        if ($this->fromNoteId !== null) {
            $note = Note::find($this->fromNoteId);

            if ($note !== null && Auth::user()?->can('update', $note)) {
                app(ConvertNote::class)->handle($note, $task);
                $this->dispatch('note-saved');
            }
        }

        $reference = $project->short_name.'-'.$task->task_number;
        $url = route('task.show', ['short_name' => $project->short_name, 'task_number' => $task->task_number]);

        // "Create another" keeps the dialog open with the project, parent, priority
        // and status retained so a run of sibling tasks can be entered quickly.
        if ($this->createAnother) {
            $this->resetForNextTask();
            $this->dispatch('create-task-focus-title');
        } else {
            $this->resetForm();
            $this->show = false;
        }

        $this->dispatch('task-created');

        // A longer, dismissible success toast that links straight to the new task.
        Flux::toast(
            text: __('Task created.'),
            duration: 10000,
            variant: 'success',
            link: [
                'text' => $reference,
                'href' => $url,
                'navigate' => true,
            ],
        );
    }

    /**
     * One-click self-assignment: stage the current user as an assignee. The
     * creator is always a member of any project they can pick, so no membership
     * check is needed here; subscription and logging happen in {@see applyAssignees}
     * when the task is saved.
     */
    public function assignToMe(): void
    {
        $userId = Auth::id();

        if (! in_array($userId, $this->assigneeIds, true)) {
            $this->assigneeIds[] = (int) $userId;
        }
    }

    /**
     * Re-scope the parent and member options whenever the chosen project changes,
     * dropping selections that no longer belong to it.
     */
    public function updatedProjectId(): void
    {
        unset($this->parentOptions, $this->members, $this->taskTypes);
        $this->parentId = null;
        $this->assigneeIds = [];
        $this->typeId = null;
    }

    /**
     * Reset the form to its defaults, ready for the next open.
     */
    protected function resetForm(): void
    {
        $this->reset(
            'projectId', 'parentId', 'fromNoteId', 'title', 'description', 'dueDate', 'createAnother',
            'typeId', 'tagNames', 'tagColors', 'tagQuery', 'showTagColorModal', 'newTagName', 'assigneeIds',
        );
        $this->priority = Priority::default()->value;
        $this->status = Status::Planned->value;
        $this->newTagColor = 'zinc';
        unset($this->parentOptions, $this->members, $this->taskTypes, $this->tagSuggestions);
    }

    /**
     * Clear only the per-task content after a "create another" save, keeping the
     * project, parent, priority and status as a template for the next one.
     */
    protected function resetForNextTask(): void
    {
        $this->reset(
            'fromNoteId', 'title', 'description', 'dueDate',
            'typeId', 'tagNames', 'tagColors', 'tagQuery', 'showTagColorModal', 'newTagName', 'assigneeIds',
        );
        $this->newTagColor = 'zinc';
        unset($this->tagSuggestions);
    }

    /**
     * Stage a tag name with its color, ignoring blanks and case-insensitive
     * duplicates.
     */
    protected function stageTag(string $name, string $color): void
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

        $this->tagColors[$name] = $color;

        $this->reset('tagQuery');
        unset($this->tagSuggestions);
    }

    /**
     * Create (or reuse) the staged tags and attach them to the new task. A
     * brand-new tag is created with the color chosen for it.
     */
    protected function applyTags(Task $task): void
    {
        if ($this->tagNames === []) {
            return;
        }

        $tagIds = collect($this->tagNames)
            ->map(fn (string $name): int => Tag::findOrCreateForProject(
                $task->project_id,
                $name,
                $this->tagColors[$name] ?? Tag::colorForName($name),
            )->getKey())
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
        return $task->nestingDepth() < (int) config('kanvigo.tasks.max_depth');
    }

    protected function selectedProject(): ?Project
    {
        if ($this->projectId === null) {
            return null;
        }

        return $this->projects()->firstWhere('id', $this->projectId);
    }

    /**
     * Build a depth resolver over a keyed task collection, counting the root as
     * level 1 by walking the in-memory parent chain (no extra queries).
     *
     * @param  Collection<int, Task>  $byId
     * @return callable(int): int
     */
    protected function depthResolver(Collection $byId): callable
    {
        return static function (int $id) use ($byId): int {
            $depth = 1;
            $task = $byId->get($id);

            while ($task?->parent_id !== null) {
                $task = $byId->get($task->parent_id);
                $depth++;
            }

            return $depth;
        };
    }
}
