<?php

namespace App\Actions;

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a user from a project: the project_user pivot row and all of their
 * roles on it, written together in one transaction. Shared by the project member
 * panel and the user-administration screen. Callers authorize and guard the
 * owner / acting user first.
 */
class RemoveProjectMember
{
    public function __construct(private readonly ProjectRoleProvisioner $provisioner) {}

    public function handle(Project $project, User $user): void
    {
        DB::transaction(function () use ($project, $user): void {
            $project->members()->detach($user->getKey());
            $this->provisioner->syncMember($project, $user, null);
        });
    }
}
