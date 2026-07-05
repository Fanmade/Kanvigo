<?php

namespace App\Actions;

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Support\Facades\DB;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

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

            Audit::record(AuditEvent::make('member_removed', AuditCategory::Authz)
                ->withSubject($project->getMorphClass(), $project->getKey())
                ->withMetadata(['member_id' => $user->getKey(), 'member' => $user->name]));
        });
    }
}
