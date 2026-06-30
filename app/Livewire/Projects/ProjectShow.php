<?php

namespace App\Livewire\Projects;

use App\Actions\AddProjectMember;
use App\Actions\RemoveProjectMember;
use App\Authorization\ProjectRoleProvisioner;
use App\Concerns\HandlesAttachments;
use App\Concerns\HasLiveUpdates;
use App\Concerns\ManagesNotes;
use App\Models\Note;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\BoardCache;
use Fanmade\DelegatedPermissions\Exceptions\RoleLimitExceeded;
use Fanmade\DelegatedPermissions\Models\Role;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * @property-read Project $project
 * @property-read EloquentCollection<int, Task> $rootTasks
 * @property-read Collection<int, Role> $assignableRoles
 */
class ProjectShow extends Component
{
    use HandlesAttachments;
    use HasLiveUpdates;
    use ManagesNotes;

    /**
     * The per-user preference key controlling whether the task list section starts
     * collapsed on the project overview.
     */
    public const string TASKS_COLLAPSED_PREFERENCE_KEY = 'project_tasks_collapsed';

    #[Locked]
    public string $shortName;

    public bool $editing = false;

    public string $title = '';

    public string $short_name = '';

    public string $description = '';

    /**
     * Per-project auto-archive threshold in days: null inherits the global
     * default, 0 disables auto-archiving for this project.
     */
    public ?int $autoArchiveDays = null;

    public bool $showArchived = false;

    /**
     * Whether the task list section is collapsed. Collapsed by default (mirroring
     * the saved preference) so the overview leads with the description, not a long
     * task list.
     */
    public bool $tasksCollapsed = true;

    /**
     * Whether closed tasks (Done & Canceled) are included in the task list. Hidden
     * by default so the list focuses on active work.
     */
    public bool $showClosed = false;

    /**
     * Narrowing task-list filters. Each is multi-valued; an empty list means "all".
     * A task has a single priority, so the priority filter is always "any of".
     *
     * @var list<int|string>
     */
    public array $priorityFilters = [];

    /**
     * Selected tag ids, with the match mode controlling whether a task must carry
     * any of them or all of them.
     *
     * @var list<int|string>
     */
    public array $tagFilters = [];

    public string $tagMatch = 'any';

    /**
     * Selected assignee ids, with the match mode controlling whether a task must be
     * assigned to any of them or all of them.
     *
     * @var list<int|string>
     */
    public array $assigneeFilters = [];

    public string $assigneeMatch = 'any';

    public bool $managingMembers = false;

    public bool $managingRoles = false;

    public string $memberQuery = '';

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('view', $this->project);

        $this->tasksCollapsed = (bool) auth()->user()?->preference(self::TASKS_COLLAPSED_PREFERENCE_KEY, true);
    }

    /**
     * Toggle the task list section and persist the state as a user preference.
     */
    public function toggleTasksCollapsed(): void
    {
        $this->tasksCollapsed = ! $this->tasksCollapsed;

        auth()->user()?->setPreference(self::TASKS_COLLAPSED_PREFERENCE_KEY, $this->tasksCollapsed);
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)->firstOrFail();

        $this->authorize('view', $project);

        return $project;
    }

    /**
     * The project's top-level tasks with the data the overview cards need: tags,
     * assignees, and the descendant subtree that drives each card's rolled-up
     * progress and direct-subtask preview.
     *
     * Cached under the project's board freshness token — the same mechanism the
     * kanban boards use — so an idle live-refresh poll is a cheap version read
     * plus cache hit instead of re-scanning every root task's full subtree. Any
     * task write bumps the version (via the Task `saved` hook) and the next
     * render rebuilds.
     *
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function rootTasks(): EloquentCollection
    {
        $project = $this->project;

        return BoardCache::remember(
            "project:overview:{$project->id}:roots:v".BoardCache::version($project->id),
            static fn (): EloquentCollection => $project->rootTasks()
                ->with(['tags', 'assignees', 'descendants'])
                ->get(),
        );
    }

    protected function attachable(): Project|Task
    {
        return $this->project;
    }

    /**
     * The system-wide default number of days before Done tasks auto-archive,
     * surfaced on the per-project field so a member can see what leaving it blank
     * inherits (0 means auto-archiving is off by default).
     */
    #[Computed]
    public function defaultAutoArchiveDays(): int
    {
        return (int) config('kanvigo.tasks.auto_archive_days', 0);
    }

    /**
     * The endpoint the editor fetches @mention / #reference suggestions from.
     */
    #[Computed]
    public function mentionablesUrl(): string
    {
        return route('project.mentionables', $this->project);
    }

    /**
     * Active (non-archived) top-level tasks that are still in progress, after the
     * active priority/tag/assignee filters.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function openTasks(): Collection
    {
        return $this->filterTasks(
            $this->rootTasks
                ->reject(static fn (Task $task): bool => $task->isArchived())
                ->reject(static fn (Task $task): bool => $task->status->isTerminal())
        );
    }

    /**
     * Active (non-archived) top-level tasks that are done or canceled, after the
     * active priority/tag/assignee filters.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function completedTasks(): Collection
    {
        return $this->filterTasks(
            $this->rootTasks
                ->reject(static fn (Task $task): bool => $task->isArchived())
                ->filter(static fn (Task $task): bool => $task->status->isTerminal())
        );
    }

    /**
     * Archived top-level tasks, surfaced only behind the "Show archived" toggle,
     * after the active priority/tag/assignee filters.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function archivedTasks(): Collection
    {
        return $this->filterTasks(
            $this->rootTasks
                ->filter(static fn (Task $task): bool => $task->isArchived())
        );
    }

    /**
     * Apply the active priority/tag/assignee filters to a set of root tasks. An
     * empty filter is a no-op, so the default view shows everything. Priority is
     * "any of" (a task has one priority); tags and assignees honour their match
     * mode — "any" keeps a task sharing at least one selection, "all" requires
     * every selection to be present.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    protected function filterTasks(Collection $tasks): Collection
    {
        if ($this->priorityFilters !== []) {
            $priorities = array_map('intval', $this->priorityFilters);
            $tasks = $tasks->filter(static fn (Task $task): bool => in_array($task->priority->value, $priorities, true));
        }

        if ($this->tagFilters !== []) {
            $tagIds = array_map('intval', $this->tagFilters);
            $tasks = $tasks->filter(fn (Task $task): bool => $this->matchesIds(
                $task->tags->pluck('id')->all(),
                $tagIds,
                $this->tagMatch,
            ));
        }

        if ($this->assigneeFilters !== []) {
            $userIds = array_map('intval', $this->assigneeFilters);
            $tasks = $tasks->filter(fn (Task $task): bool => $this->matchesIds(
                $task->assignees->pluck('id')->all(),
                $userIds,
                $this->assigneeMatch,
            ));
        }

        return $tasks->values();
    }

    /**
     * Whether a task's ids satisfy the selected ids under the given match mode:
     * "all" requires every selected id to be present, otherwise at least one.
     *
     * @param  array<array-key, mixed>  $taskIds
     * @param  list<int>  $selectedIds
     */
    protected function matchesIds(array $taskIds, array $selectedIds, string $match): bool
    {
        $present = array_intersect($selectedIds, array_map('intval', $taskIds));

        return $match === 'all'
            ? count($present) === count($selectedIds)
            : $present !== [];
    }

    /**
     * How many task-list filters are currently narrowing the view, for the count
     * badge on the "Filters" button.
     */
    #[Computed]
    public function activeTaskFilterCount(): int
    {
        return ($this->priorityFilters !== [] ? 1 : 0)
            + ($this->tagFilters !== [] ? 1 : 0)
            + ($this->assigneeFilters !== [] ? 1 : 0)
            + ($this->showClosed ? 1 : 0)
            + ($this->showArchived ? 1 : 0);
    }

    /**
     * Whether the project has any archived root tasks at all (ignoring filters),
     * so the "Show archived" toggle only appears when it can do something.
     */
    #[Computed]
    public function hasArchivedRootTasks(): bool
    {
        return $this->rootTasks->contains(static fn (Task $task): bool => $task->isArchived());
    }

    /**
     * The project's tags, for the task-list tag filter.
     *
     * @return EloquentCollection<int, Tag>
     */
    #[Computed]
    public function projectTags(): EloquentCollection
    {
        return $this->project->tags()->orderBy('name')->get();
    }

    /**
     * The project's public notes (any owner), newest first. Private attached
     * notes are never listed here — they stay with their owner.
     *
     * @return EloquentCollection<int, Note>
     */
    #[Computed]
    public function publicNotes(): EloquentCollection
    {
        return $this->project->notes()
            ->where('is_public', true)
            ->with('user')
            ->latest('updated_at')
            ->get();
    }

    protected function forgetNotes(): void
    {
        unset($this->publicNotes);
    }

    /**
     * Archive a top-level task, removing it from the project overview and board.
     */
    public function archiveTask(int $taskId): void
    {
        $task = $this->project->rootTasks()->whereKey($taskId)->firstOrFail();

        $this->authorize('archive', $task);

        $task->archive();

        unset($this->project, $this->rootTasks);

        Flux::toast(variant: 'success', text: __('Task archived.'));
    }

    /**
     * Restore a top-level task from the archive.
     */
    public function unarchiveTask(int $taskId): void
    {
        $task = $this->project->rootTasks()->whereKey($taskId)->firstOrFail();

        $this->authorize('archive', $task);

        $task->unarchive();

        unset($this->project, $this->rootTasks);

        Flux::toast(variant: 'success', text: __('Task restored.'));
    }

    public function edit(): void
    {
        $this->authorize('manageSettings', $this->project);

        $this->title = $this->project->title;
        $this->short_name = $this->project->short_name;
        $this->description = (string) $this->project->description;
        $this->autoArchiveDays = $this->project->auto_archive_days;
        $this->editing = true;
    }

    public function save(): void
    {
        $project = $this->project;

        $this->authorize('manageSettings', $project);

        $this->short_name = strtoupper($this->short_name);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'short_name' => Project::shortNameRules($project->id),
            'description' => ['nullable', 'string'],
            'autoArchiveDays' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        $shortNameChanged = $project->short_name !== $validated['short_name'];

        $project->update([
            'title' => $validated['title'],
            'short_name' => $validated['short_name'],
            'description' => $validated['description'],
            'auto_archive_days' => $validated['autoArchiveDays'],
        ]);

        $this->editing = false;
        unset($this->project);

        Flux::toast(variant: 'success', text: __('Project updated.'));

        // The short name is the route key, so move to the new URL when it changes.
        if ($shortNameChanged) {
            $this->shortName = $validated['short_name'];
            $this->redirectRoute('project.show', ['short_name' => $validated['short_name']], navigate: true);
        }
    }

    /**
     * The project's members, ordered by name, each with their project-scoped
     * role eager-loaded for the member-management panel.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function members(): EloquentCollection
    {
        $project = $this->project;

        return $project->members()
            ->with(['roles' => static fn ($query) => $query
                ->where('scope_type', $project->getMorphClass())
                ->where('scope_id', $project->getKey())])
            ->orderBy('name')
            ->get();
    }

    /**
     * The roles a member may be assigned — every role the manager can see (per
     * KAN-312 visibility) except owner, so ownership cannot be handed out here.
     * Includes the project's custom roles. The seeded roles sort first, custom
     * roles after, by name.
     *
     * @return Collection<int, Role>
     */
    #[Computed]
    public function assignableRoles(): Collection
    {
        return Auth::user()->visibleRoles($this->project)
            ->reject(static fn (Role $role): bool => $role->name === 'owner')
            ->sortBy(static fn (Role $role): string => sprintf(
                '%d-%s',
                in_array($role->name, ['admin', 'member', 'viewer'], true) ? 0 : 1,
                $role->name,
            ))
            ->values();
    }

    /**
     * A human-readable label for a role name (e.g. "member" → "Member").
     */
    public function roleLabel(?string $name): string
    {
        return $name === null ? '' : Str::headline($name);
    }

    /**
     * Whether the viewer can edit the project — gates the description editor,
     * the attachment dropzone and the task-creation control. Read in place of
     * re-running the `update` policy check at each of those sites.
     */
    #[Computed]
    public function canUpdate(): bool
    {
        return Auth::user()?->can('update', $this->project) ?? false;
    }

    /**
     * The per-row data for a member in the management list: whether their roles
     * are read-only (it's the viewer themselves, or an owner whose roles are
     * fixed here) and which assignable roles they do not already hold and could
     * still be granted.
     *
     * @return array{readonly: bool, addable: Collection<int, Role>}
     */
    public function memberRow(User $member): array
    {
        $heldNames = $member->roles->pluck('name');

        return [
            'readonly' => $member->getKey() === Auth::id() || $heldNames->contains('owner'),
            'addable' => $this->assignableRoles
                ->reject(static fn (Role $role): bool => $heldNames->contains($role->name))
                ->values(),
        ];
    }

    /**
     * Grant a member an additional role, leaving their other roles intact.
     * Requires manage-members, and is limited to the project's assignable
     * (non-owner, visible) roles. Owners and the acting user are left untouched.
     */
    public function addMemberRole(int $userId, string $role): void
    {
        $project = $this->project;
        $this->authorize('manageMembers', $project);

        if ($userId === auth()->id()) {
            return;
        }

        $validated = validator(
            ['role' => $role],
            ['role' => ['required', Rule::in($this->assignableRoles->pluck('name')->all())]],
        )->validate();

        $member = User::find($userId);

        if ($member === null || ! $project->members()->whereKey($userId)->exists() || $project->isOwner($member)) {
            return;
        }

        try {
            app(ProjectRoleProvisioner::class)->addRole($project, $member, $validated['role']);
        } catch (RoleLimitExceeded) {
            Flux::toast(variant: 'warning', text: __('This member already holds the maximum number of roles.'));

            return;
        }

        unset($this->members);

        Flux::toast(variant: 'success', text: __('Member role added.'));
    }

    /**
     * Remove a single role from a member, leaving their other roles intact.
     * Requires manage-members; the owner role and the acting user are untouched.
     *
     * Removing a member's last role would otherwise leave a "ghost" pivot row
     * that the role-based policies no longer back (still listed in members() but
     * denied by ProjectPolicy::view). To keep the pivot and role assignments in
     * agreement, dropping the final role removes the membership outright.
     */
    public function removeMemberRole(int $userId, string $role): void
    {
        $project = $this->project;
        $this->authorize('manageMembers', $project);

        if ($userId === auth()->id() || $role === 'owner') {
            return;
        }

        $member = User::find($userId);

        if ($member === null || ! $project->members()->whereKey($userId)->exists() || $project->isOwner($member)) {
            return;
        }

        $removedMember = DB::transaction(function () use ($project, $member, $role): bool {
            app(ProjectRoleProvisioner::class)->removeRole($project, $member, $role);

            if ($project->roleNamesFor($member) !== []) {
                return false;
            }

            $project->members()->detach($member->getKey());

            return true;
        });

        unset($this->members, $this->addableUsers);

        Flux::toast(
            variant: 'success',
            text: $removedMember ? __('Member removed.') : __('Member role removed.'),
        );
    }

    /**
     * Existing users not yet on the project that match the search, offered in
     * the add-member picker. Empty until the owner types a query.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function addableUsers(): EloquentCollection
    {
        $query = trim($this->memberQuery);

        if ($query === '') {
            return new EloquentCollection;
        }

        return User::query()
            ->whereNotIn('id', $this->project->members()->pluck('users.id'))
            ->where(static fn ($builder) => $builder
                ->whereLike('name', '%'.$query.'%')
                ->orWhereLike('email', '%'.$query.'%'))
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    /**
     * Add an existing user to the project as a member. Owner-only.
     */
    public function addMember(int $userId): void
    {
        $project = $this->project;
        $this->authorize('manageMembers', $project);

        if (! User::whereKey($userId)->exists() || $project->members()->whereKey($userId)->exists()) {
            return;
        }

        app(AddProjectMember::class)->handle($project, User::findOrFail($userId));

        $this->memberQuery = '';
        unset($this->members, $this->addableUsers);

        Flux::toast(variant: 'success', text: __('Member added.'));
    }

    /**
     * Remove a member from the project. Owner-only; the owner cannot remove
     * themselves (which would leave the project without an owner).
     */
    public function removeMember(int $userId): void
    {
        $project = $this->project;
        $this->authorize('manageMembers', $project);

        if ($userId === auth()->id() || ! $project->members()->whereKey($userId)->exists()) {
            return;
        }

        app(RemoveProjectMember::class)->handle($project, User::findOrFail($userId));

        unset($this->members, $this->addableUsers);

        Flux::toast(variant: 'success', text: __('Member removed.'));
    }

    /**
     * Refresh the task lists after one is created through the shared create dialog.
     */
    #[On('task-created')]
    public function refreshAfterCreate(): void
    {
        unset($this->project, $this->rootTasks);
    }

    /**
     * Live-updates tick: pull in task changes made by others (the comment and
     * activity feeds refresh themselves on the same event). The poll that fires
     * this already skips ticks while the user is editing.
     */
    #[On('live-refresh')]
    public function liveRefresh(): void
    {
        unset($this->project, $this->rootTasks, $this->publicNotes);
    }
}
