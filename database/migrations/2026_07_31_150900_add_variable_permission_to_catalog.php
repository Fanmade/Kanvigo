<?php

use App\Authorization\ProjectPermission;
use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\PermissionGroup;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The variable permission introduced by this migration (KAN-457).
     *
     * @var list<string>
     */
    private const array VARIABLE_PERMISSIONS = ['manage-variables'];

    /**
     * Seed the new permission and its group, then re-grant the recomputed
     * permission sets to every existing project role tree, so variables become
     * manageable by the same roles that already manage tags (owner/admin/member);
     * viewers stay read-only. New projects get this from the provisioner directly.
     */
    public function up(): void
    {
        app(ProjectRoleProvisioner::class)->seedCatalog();

        $permissionIds = Permission::query()
            ->whereIn('name', ProjectPermission::names())
            ->pluck('id', 'name');

        $idsFor = static fn (string $role): array => array_map(
            static fn (string $name): int => $permissionIds[$name],
            ProjectRoleProvisioner::grants()[$role],
        );

        Project::query()->each(static function (Project $project) use ($idsFor): void {
            $roles = Role::query()
                ->where('scope_type', $project->getMorphClass())
                ->where('scope_id', $project->getKey())
                ->get()
                ->keyBy('name');

            // Re-sync the base roles in place — the recomputed sets are supersets,
            // so the delegation bounds (child ⊆ parent) still hold.
            foreach (['owner', 'admin', 'member'] as $name) {
                $roles->get($name)?->permissions()->syncWithoutDetaching($idsFor($name));
            }
        });
    }

    /**
     * Remove the variable permission (its role grants and group links cascade
     * away) and the Variables group.
     */
    public function down(): void
    {
        Permission::query()->whereIn('name', self::VARIABLE_PERMISSIONS)->delete();

        PermissionGroup::query()->where('name', 'Variables')->delete();
    }
};
