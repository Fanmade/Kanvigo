<?php

namespace App\Livewire\Projects;

use App\Authorization\ProjectRoleProvisioner;
use App\Concerns\HandlesAttachments;
use App\Concerns\HasLiveUpdates;
use App\Concerns\ManagesNotes;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Role;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ProjectShow extends Component
{
    use HandlesAttachments;
    use HasLiveUpdates;
    use ManagesNotes;

    #[Locked]
    public string $shortName;

    public bool $editing = false;

    public string $title = '';

    public string $short_name = '';

    public string $description = '';

    public bool $showArchived = false;

    public bool $managingMembers = false;

    public bool $managingRoles = false;

    public string $memberQuery = '';

    public function mount(string $short_name): void
    {
        $this->shortName = $short_name;

        $this->authorize('view', $this->project());
    }

    #[Computed]
    public function project(): Project
    {
        $project = Project::where('short_name', $this->shortName)
            ->with(['rootTasks.tags', 'rootTasks.assignees', 'rootTasks.descendants'])
            ->firstOrFail();

        $this->authorize('view', $project);

        return $project;
    }

    protected function attachable(): Project|Task
    {
        return $this->project();
    }

    /**
     * Active (non-archived) top-level tasks that are still in progress.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function openTasks(): Collection
    {
        return $this->project()->rootTasks
            ->reject(static fn (Task $task): bool => $task->isArchived())
            ->reject(static fn (Task $task): bool => $task->status->isTerminal())
            ->values();
    }

    /**
     * Active (non-archived) top-level tasks that are done or canceled.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function completedTasks(): Collection
    {
        return $this->project()->rootTasks
            ->reject(static fn (Task $task): bool => $task->isArchived())
            ->filter(static fn (Task $task): bool => $task->status->isTerminal())
            ->values();
    }

    /**
     * Archived top-level tasks, surfaced only behind the "Show archived" toggle.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function archivedTasks(): Collection
    {
        return $this->project()->rootTasks
            ->filter(static fn (Task $task): bool => $task->isArchived())
            ->values();
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
        return $this->project()->notes()
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
        $task = $this->project()->rootTasks()->whereKey($taskId)->firstOrFail();

        $this->authorize('update', $task);

        $task->archive();

        unset($this->project);

        Flux::toast(variant: 'success', text: __('Task archived.'));
    }

    /**
     * Restore a top-level task from the archive.
     */
    public function unarchiveTask(int $taskId): void
    {
        $task = $this->project()->rootTasks()->whereKey($taskId)->firstOrFail();

        $this->authorize('update', $task);

        $task->unarchive();

        unset($this->project);

        Flux::toast(variant: 'success', text: __('Task restored.'));
    }

    public function edit(): void
    {
        $this->authorize('manageSettings', $this->project());

        $this->title = $this->project()->title;
        $this->short_name = $this->project()->short_name;
        $this->description = (string) $this->project()->description;
        $this->editing = true;
    }

    public function save(): void
    {
        $project = $this->project();

        $this->authorize('manageSettings', $project);

        $this->short_name = strtoupper($this->short_name);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'short_name' => [
                'required', 'string', 'min:2', 'max:4', 'alpha', 'uppercase',
                Rule::notIn(['WWW', 'API', 'APP', 'FTP']),
                Rule::unique('projects', 'short_name')->ignore($project->id),
            ],
            'description' => ['nullable', 'string'],
        ]);

        $shortNameChanged = $project->short_name !== $validated['short_name'];

        $project->update($validated);

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
        $project = $this->project();

        return $project->members()
            ->with(['roles' => static fn ($query) => $query
                ->where('scope_type', $project->getMorphClass())
                ->where('scope_id', $project->getKey())])
            ->orderBy('name')
            ->get();
    }

    /**
     * The roles a member may be assigned — every project role except owner, so
     * ownership cannot be handed out here. Includes the custom roles defined for
     * the project. The seeded roles sort first, custom roles after, by name.
     *
     * @return EloquentCollection<int, Role>
     */
    #[Computed]
    public function assignableRoles(): EloquentCollection
    {
        $project = $this->project();

        return Role::query()
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->getKey())
            ->where('name', '!=', 'owner')
            ->get()
            ->sortBy(static fn (Role $role): string => sprintf(
                '%d-%s',
                in_array($role->name, ['admin', 'member'], true) ? 0 : 1,
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
     * Change a member's role. Requires manage-members, and is limited to the
     * project's assignable (non-owner) roles — the owner can neither change their
     * own role nor hand out ownership here.
     */
    public function setMemberRole(int $userId, string $role): void
    {
        $project = $this->project();
        $this->authorize('manageMembers', $project);

        if ($userId === auth()->id()) {
            return;
        }

        $validated = validator(
            ['role' => $role],
            ['role' => ['required', Rule::in($this->assignableRoles()->pluck('name')->all())]],
        )->validate();

        $member = User::find($userId);

        if ($member === null || ! $project->members()->whereKey($userId)->exists() || $project->isOwner($member)) {
            return;
        }

        app(ProjectRoleProvisioner::class)->syncMember($project, $member, $validated['role']);

        unset($this->members);

        Flux::toast(variant: 'success', text: __('Member role updated.'));
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
            ->whereNotIn('id', $this->project()->members()->pluck('users.id'))
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
        $project = $this->project();
        $this->authorize('manageMembers', $project);

        if (! User::whereKey($userId)->exists() || $project->members()->whereKey($userId)->exists()) {
            return;
        }

        $project->members()->attach($userId);
        app(ProjectRoleProvisioner::class)->syncMember($project, User::findOrFail($userId), 'member');

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
        $project = $this->project();
        $this->authorize('manageMembers', $project);

        if ($userId === auth()->id() || ! $project->members()->whereKey($userId)->exists()) {
            return;
        }

        $project->members()->detach($userId);
        app(ProjectRoleProvisioner::class)->syncMember($project, User::findOrFail($userId), null);

        unset($this->members, $this->addableUsers);

        Flux::toast(variant: 'success', text: __('Member removed.'));
    }

    /**
     * Refresh the task lists after one is created through the shared create dialog.
     */
    #[On('task-created')]
    public function refreshAfterCreate(): void
    {
        unset($this->project);
    }

    /**
     * Live-updates tick: pull in task changes made by others (the comment and
     * activity feeds refresh themselves on the same event). The poll that fires
     * this already skips ticks while the user is editing.
     */
    #[On('live-refresh')]
    public function liveRefresh(): void
    {
        unset($this->project, $this->publicNotes);
    }
}
