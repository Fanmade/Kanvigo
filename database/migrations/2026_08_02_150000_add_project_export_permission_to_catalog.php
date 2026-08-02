<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The whole-project export permission introduced by this migration (KAN-480).
     *
     * @var list<string>
     */
    private const array EXPORT_PERMISSIONS = ['export-project'];

    /**
     * Seed the permission and re-grant the recomputed sets to every existing
     * project role tree. Unlike `export-content`, this one stops at admins and
     * the owner: taking an entire project out in one archive is a different act
     * from exporting the task you happen to be reading.
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

            // Re-sync in place — the recomputed sets are supersets, so the
            // delegation bounds (child ⊆ parent) still hold.
            foreach (['owner', 'admin', 'member'] as $name) {
                $roles->get($name)?->permissions()->syncWithoutDetaching($idsFor($name));
            }
        });
    }

    /**
     * Remove the permission; its role grants and group links cascade away.
     */
    public function down(): void
    {
        Permission::query()->whereIn('name', self::EXPORT_PERMISSIONS)->delete();
    }
};
