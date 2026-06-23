<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the project. Any member may.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->hasScopedPermission('view-project', $project);
    }

    /**
     * Determine whether the user can create projects.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CreateProjects);
    }

    /**
     * Determine whether the user can contribute to the project — creating and
     * working on its tasks, attachments and the like. Any member may.
     */
    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    /**
     * Determine whether the user can edit the project's own settings — its
     * title, short name and description. Restricted to admins and the owner.
     */
    public function manageSettings(User $user, Project $project): bool
    {
        return $user->hasScopedPermission('manage-settings', $project);
    }

    /**
     * Determine whether the user can delete the project. Admins and the owner.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->hasScopedPermission('delete-project', $project);
    }

    /**
     * Determine whether the user can manage the project's members and their
     * roles. Restricted to holders of the manage-members permission (the owner,
     * or any custom role granted it).
     */
    public function manageMembers(User $user, Project $project): bool
    {
        return $user->hasScopedPermission('manage-members', $project);
    }
}
