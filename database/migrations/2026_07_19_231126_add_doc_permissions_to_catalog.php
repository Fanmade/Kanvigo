<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\PermissionGroup;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The doc permissions introduced by this migration (KAN-438). Everything
     * else in the catalog predates it.
     *
     * @var list<string>
     */
    private const array DOC_PERMISSIONS = ['create-doc', 'edit-doc', 'delete-doc'];

    /**
     * Seed the new doc permissions and their group, then re-grant the recomputed
     * permission sets to every existing project role tree so docs become editable
     * by the same roles that already manage tasks (owner/admin/member); viewers
     * stay read-only. New projects get these from the provisioner directly.
     */
    public function up(): void
    {
        app(ProjectRoleProvisioner::class)->seedCatalog();

        $permissionIds = Permission::query()
            ->whereIn('name', ProjectRoleProvisioner::CATALOG)
            ->pluck('id', 'name');

        $idsFor = static fn (string $role): array => array_map(
            static fn (string $name): int => $permissionIds[$name],
            ProjectRoleProvisioner::GRANTS[$role],
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
     * Remove the doc permissions (their role grants and group links cascade away)
     * and the Docs group. Base roles fall back to their surviving permissions.
     */
    public function down(): void
    {
        Permission::query()->whereIn('name', self::DOC_PERMISSIONS)->delete();

        PermissionGroup::query()->where('name', 'Docs')->delete();
    }
};
