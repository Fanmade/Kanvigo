<?php

namespace App\Livewire\Admin;

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Permission;
use App\Enums\ProjectRole;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('User administration')]
class UserManagement extends Component
{
    #[Url]
    public string $search = '';

    /**
     * Whether the removal confirmation modal is open.
     */
    public bool $confirmingRemoval = false;

    /**
     * The id of the user currently pending removal confirmation.
     */
    public ?int $confirmingRemovalId = null;

    /**
     * Whether the manage-projects modal is open, and for which user.
     */
    public bool $managingProjects = false;

    public ?int $managingProjectsId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * The permission cases an administrator may grant or revoke.
     *
     * @return array<int, Permission>
     */
    #[Computed]
    public function permissions(): array
    {
        return Permission::cases();
    }

    /**
     * All user accounts with their granted permissions and the invitations they
     * have sent that are still pending, optionally filtered.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()
            ->with(['permissions', 'pendingInvitations'])
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(static function ($query) use ($term): void {
                    $query->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Pending (unaccepted, unexpired) invitations awaiting a response.
     *
     * @return Collection<int, Invitation>
     */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return Invitation::query()->valid()->with('inviter')->latest()->get();
    }

    /**
     * Whether toggling the given permission would let an administrator revoke
     * their own management access and lock themselves out of this area.
     */
    public function locksSelfOutOfManagement(User $user, Permission $permission): bool
    {
        return $permission === Permission::ManageUsers && $user->is(Auth::user());
    }

    /**
     * Grant or revoke a single permission for the given user.
     */
    public function togglePermission(int $userId, string $permission): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('update', $user);

        $case = Permission::from($permission);
        $granted = $user->hasPermission($case);

        if ($granted && $this->locksSelfOutOfManagement($user, $case)) {
            Flux::toast(text: __('You cannot revoke your own user management permission.'), variant: 'warning');

            return;
        }

        $values = $user->permissions
            ->map(static fn ($userPermission): Permission => $userPermission->permission)
            ->reject(static fn (Permission $existing): bool => $granted && $existing === $case)
            ->all();

        if (! $granted) {
            $values[] = $case;
        }

        $user->syncPermissions($values);

        unset($this->users);

        Flux::toast(text: __('Permissions updated.'), variant: 'success');
    }

    /**
     * Deactivate a user account, preventing them from signing in.
     */
    public function deactivate(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('deactivate', $user);

        $user->deactivate();

        unset($this->users);

        Flux::toast(text: __('Account deactivated.'), variant: 'success');
    }

    /**
     * Restore a previously deactivated account.
     */
    public function reactivate(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('deactivate', $user);

        $user->reactivate();

        unset($this->users);

        Flux::toast(text: __('Account reactivated.'), variant: 'success');
    }

    /**
     * Ask the user to confirm removing an account.
     */
    public function confirmRemoval(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('delete', $user);

        $this->confirmingRemovalId = $userId;
        $this->confirmingRemoval = true;
    }

    /**
     * Dismiss the removal confirmation.
     */
    public function cancelRemoval(): void
    {
        $this->confirmingRemovalId = null;
        $this->confirmingRemoval = false;
    }

    /**
     * The user currently pending removal confirmation, if any.
     */
    #[Computed]
    public function removalTarget(): ?User
    {
        return $this->confirmingRemovalId !== null
            ? User::find($this->confirmingRemovalId)
            : null;
    }

    /**
     * Remove a user account. The account is soft-deleted and its project access,
     * task assignments and subscriptions are detached; authored comments survive
     * as the work of a removed user.
     */
    public function removeUser(): void
    {
        $user = User::findOrFail($this->confirmingRemovalId);

        $this->authorize('delete', $user);

        $user->delete();

        $this->confirmingRemovalId = null;
        $this->confirmingRemoval = false;

        unset($this->users);

        Flux::toast(text: __('Account removed.'), variant: 'success');
    }

    /**
     * Re-send a pending invitation, refreshing its expiry.
     */
    public function resendInvitation(int $invitationId): void
    {
        $this->authorize('manage-users');

        $invitation = Invitation::query()->valid()->findOrFail($invitationId);

        $invitation->forceFill(['expires_at' => now()->addDays(7)])->save();

        Mail::to($invitation->email)->send(new InvitationMail($invitation, $invitation->token));

        unset($this->pendingInvitations);

        Flux::toast(text: __('Invitation resent.'), variant: 'success');
    }

    /**
     * Revoke a pending invitation so it can no longer be accepted.
     */
    public function revokeInvitation(int $invitationId): void
    {
        $this->authorize('manage-users');

        Invitation::query()->valid()->findOrFail($invitationId)->delete();

        unset($this->pendingInvitations);

        Flux::toast(text: __('Invitation revoked.'), variant: 'success');
    }

    /**
     * Open the manage-projects modal for a user.
     */
    public function manageProjects(int $userId): void
    {
        $this->authorize('manage-users');

        $this->managingProjectsId = $userId;
        $this->managingProjects = true;
    }

    /**
     * The user whose project memberships are being managed.
     */
    #[Computed]
    public function managedUser(): ?User
    {
        return $this->managingProjectsId !== null ? User::find($this->managingProjectsId) : null;
    }

    /**
     * Every project, for the manage-projects modal.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function manageableProjects(): Collection
    {
        return Project::query()->orderBy('title')->get();
    }

    /**
     * The managed user's role on each project they belong to, keyed by project id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function managedUserRoles(): array
    {
        if ($this->managingProjectsId === null) {
            return [];
        }

        $roles = [];

        foreach (DB::table('project_user')->where('user_id', $this->managingProjectsId)->get(['project_id', 'role']) as $row) {
            $roles[(int) $row->project_id] = (string) $row->role;
        }

        return $roles;
    }

    /**
     * Add the managed user to a project as a member.
     */
    public function addUserToProject(int $projectId): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('manage-members', $project);

        $user = User::findOrFail($this->managingProjectsId);

        if ($project->members()->whereKey($user->id)->exists()) {
            return;
        }

        $project->members()->attach($user->id, ['role' => ProjectRole::Member->value]);
        app(ProjectRoleProvisioner::class)->syncMember($project, $user, ProjectRole::Member->value);

        unset($this->managedUser, $this->managedUserRoles, $this->users);

        Flux::toast(text: __('Member added.'), variant: 'success');
    }

    /**
     * Change the managed user's role on a project. Limited to admin/member; the
     * owner's role is not reassigned here.
     */
    public function setUserProjectRole(int $projectId, string $role): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('manage-members', $project);

        $validated = validator(
            ['role' => $role],
            ['role' => ['required', Rule::in([ProjectRole::Admin->value, ProjectRole::Member->value])]],
        )->validate();

        $user = User::findOrFail($this->managingProjectsId);

        if (! $project->members()->whereKey($user->id)->exists() || $project->isOwner($user)) {
            return;
        }

        $project->members()->updateExistingPivot($user->id, ['role' => $validated['role']]);
        app(ProjectRoleProvisioner::class)->syncMember($project, $user, $validated['role']);

        unset($this->managedUser, $this->managedUserRoles);

        Flux::toast(text: __('Member role updated.'), variant: 'success');
    }

    /**
     * Remove the managed user from a project. The project's owner cannot be removed.
     */
    public function removeUserFromProject(int $projectId): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('manage-members', $project);

        $user = User::findOrFail($this->managingProjectsId);

        if (! $project->members()->whereKey($user->id)->exists() || $project->isOwner($user)) {
            return;
        }

        $project->members()->detach($user->id);
        app(ProjectRoleProvisioner::class)->syncMember($project, $user, null);

        unset($this->managedUser, $this->managedUserRoles, $this->users);

        Flux::toast(text: __('Member removed.'), variant: 'success');
    }
}
