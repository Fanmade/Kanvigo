<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserPolicy
{
    /**
     * Determine whether the user can access the user administration area.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageUsers);
    }

    /**
     * Determine whether the user can view another account's profile page. A
     * profile is visible to the user themselves, to anyone who may access all
     * projects, and to members who share at least one project with the target —
     * keeping profiles within the same collaboration boundary as everything else.
     */
    public function view(User $user, User $target): bool
    {
        if ($user->is($target) || $user->canAccessAllProjects()) {
            return true;
        }

        return Project::query()
            ->whereHas('members', static fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->whereHas('members', static fn (Builder $query): Builder => $query->whereKey($target->getKey()))
            ->exists();
    }

    /**
     * Determine whether the viewer may see another account's contact details
     * (email and other PII) exposed by the dedicated user-info endpoints. Stricter
     * than {@see view()}: a cross-project access-all grant lets a viewer resolve a
     * user's name but not their email — that stays within the actual collaboration
     * boundary (a shared project), plus user administrators.
     */
    public function viewContactInfo(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::ManageUsers)
            || $user->sharesProjectWith($target);
    }

    /**
     * Determine whether the user can fetch another account's avatar image. Same
     * boundary as viewing a profile, plus user administrators — who legitimately
     * see every account's avatar in the management panel even without a shared
     * project. A likeness stays within the collaboration (or admin) boundary
     * rather than being readable by any authenticated user.
     */
    public function viewAvatar(User $user, User $target): bool
    {
        return $this->view($user, $target) || $user->hasPermission(Permission::ManageUsers);
    }

    /**
     * Determine whether the user can change another account's permissions.
     */
    public function update(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::ManageUsers);
    }

    /**
     * Determine whether the user can deactivate or reactivate an account.
     * Administrators may not deactivate their own account.
     */
    public function deactivate(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::ManageUsers) && ! $user->is($target);
    }

    /**
     * Determine whether the user can remove an account.
     * Administrators may not remove their own account here.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::ManageUsers) && ! $user->is($target);
    }
}
