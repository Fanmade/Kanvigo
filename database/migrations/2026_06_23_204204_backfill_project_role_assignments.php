<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\PermissionResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Provision the delegated role tree for every existing project and assign each
     * member the package role matching their legacy `project_user.role`. Additive:
     * the legacy column stays in place until KAN-243.
     */
    public function up(): void
    {
        $provisioner = app(ProjectRoleProvisioner::class);
        $resolver = app(PermissionResolver::class);

        Project::query()->each(function (Project $project) use ($provisioner, $resolver): void {
            $roles = $provisioner->provision($project);

            DB::table('project_user')
                ->where('project_id', $project->getKey())
                ->get()
                ->each(function (object $member) use ($roles, $resolver): void {
                    $user = User::find((int) $member->user_id);

                    if ($user !== null) {
                        $resolver->assign($user, $roles[$member->role] ?? $roles['member']);
                    }
                });
        });
    }

    /**
     * No-op: the provisioned roles and assignments are left in place.
     */
    public function down(): void
    {
        //
    }
};
