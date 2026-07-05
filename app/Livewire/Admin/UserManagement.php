<?php

namespace App\Livewire\Admin;

use App\Actions\AddProjectMember;
use App\Actions\RemoveProjectMember;
use App\Authorization\AccountPermissionProvisioner;
use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Permission;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Support\Facades\Audit;
use Fanmade\DelegatedPermissions\Exceptions\RoleLimitExceeded;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @property-read User|null $managedUser
 * @property-read array<int, list<string>> $managedUserRoles
 */
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
            ->with(['pendingInvitations'])
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

        $provisioner = app(AccountPermissionProvisioner::class);

        if ($granted) {
            $provisioner->revoke($user, $case);
        } else {
            $provisioner->grant($user, $case);
        }

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

        Audit::record(AuditEvent::make('invitation_resent', AuditCategory::Authz)
            ->withSubject($invitation->getMorphClass(), $invitation->getKey())
            ->withMetadata(['email' => $invitation->email]));

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

        $invitation = Invitation::query()->valid()->findOrFail($invitationId);
        $invitation->delete();

        Audit::record(AuditEvent::make('invitation_revoked', AuditCategory::Authz)
            ->withSubject($invitation->getMorphClass(), $invitation->getKey())
            ->withMetadata(['email' => $invitation->email]));

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
     * The seeded non-owner roles an admin may assign here. Ownership is never
     * handed out from this panel, and custom roles are managed per-project.
     *
     * @var list<string>
     */
    public const array ASSIGNABLE_ROLES = ['admin', 'member', 'viewer'];

    /**
     * The names of every project role the managed user holds, keyed by project
     * id. A user may hold several roles on one project, so each value is a list.
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function managedUserRoles(): array
    {
        $user = $this->managedUser;

        if ($user === null) {
            return [];
        }

        $roles = [];

        foreach ($user->roles()->where('scope_type', (new Project)->getMorphClass())->get() as $role) {
            $roles[(int) $role->scope_id][] = (string) $role->name;
        }

        return $roles;
    }

    /**
     * The per-row data for a project in the manage-projects modal: the role names
     * the managed user already holds there, the assignable seeded roles still
     * available to grant, and whether the row is read-only (they own the project).
     *
     * @return array{heldNames: \Illuminate\Support\Collection<int, string>, addable: \Illuminate\Support\Collection<int, string>, readonly: bool}
     */
    public function projectRow(Project $project): array
    {
        $heldNames = collect($this->managedUserRoles[$project->id] ?? []);

        return [
            'heldNames' => $heldNames,
            'addable' => collect(self::ASSIGNABLE_ROLES)
                ->reject(static fn (string $role): bool => $heldNames->contains($role))
                ->values(),
            'readonly' => $heldNames->contains('owner'),
        ];
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

        app(AddProjectMember::class)->handle($project, $user);

        unset($this->managedUser, $this->managedUserRoles, $this->users);

        Flux::toast(text: __('Member added.'), variant: 'success');
    }

    /**
     * Grant the managed user an additional role on a project, leaving their
     * other roles intact. Limited to the seeded non-owner roles; the owner's
     * roles are not touched here.
     */
    public function addUserProjectRole(int $projectId, string $role): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('manage-members', $project);

        $validated = validator(
            ['role' => $role],
            ['role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)]],
        )->validate();

        $user = User::findOrFail($this->managingProjectsId);

        if (! $project->members()->whereKey($user->id)->exists() || $project->isOwner($user)) {
            return;
        }

        try {
            app(ProjectRoleProvisioner::class)->addRole($project, $user, $validated['role']);
        } catch (RoleLimitExceeded) {
            Flux::toast(text: __('This member already holds the maximum number of roles.'), variant: 'warning');

            return;
        }

        unset($this->managedUser, $this->managedUserRoles);

        Flux::toast(text: __('Member role added.'), variant: 'success');
    }

    /**
     * Remove a single role from the managed user on a project, leaving their
     * other roles intact. The owner role is never removed here.
     */
    public function removeUserProjectRole(int $projectId, string $role): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('manage-members', $project);

        if ($role === 'owner') {
            return;
        }

        $user = User::findOrFail($this->managingProjectsId);

        if (! $project->members()->whereKey($user->id)->exists() || $project->isOwner($user)) {
            return;
        }

        app(ProjectRoleProvisioner::class)->removeRole($project, $user, $role);

        unset($this->managedUser, $this->managedUserRoles);

        Flux::toast(text: __('Member role removed.'), variant: 'success');
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

        app(RemoveProjectMember::class)->handle($project, $user);

        unset($this->managedUser, $this->managedUserRoles, $this->users);

        Flux::toast(text: __('Member removed.'), variant: 'success');
    }
}
