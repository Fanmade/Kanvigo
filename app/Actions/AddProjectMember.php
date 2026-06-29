<?php

namespace App\Actions;

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a user to a project as a member: the project_user pivot row and the base
 * member role, written together in one transaction so a member is never left in
 * the pivot without a role (or vice versa). Shared by the project member panel
 * and the user-administration screen. Callers authorize and guard against an
 * existing membership first.
 */
class AddProjectMember
{
    public function __construct(private readonly ProjectRoleProvisioner $provisioner) {}

    public function handle(Project $project, User $user): void
    {
        DB::transaction(function () use ($project, $user): void {
            $project->members()->attach($user->getKey());
            $this->provisioner->syncMember($project, $user, 'member');
        });
    }
}
