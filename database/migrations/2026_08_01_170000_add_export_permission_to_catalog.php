<?php

use App\Authorization\ProjectPermission;
use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The export permission introduced by this migration (KAN-472).
     *
     * @var list<string>
     */
    private const array EXPORT_PERMISSIONS = ['export-content'];

    /**
     * Seed the new permission, then re-grant the recomputed permission sets to
     * every existing project role tree, so exporting is available to the roles
     * that already contribute (owner/admin/member). Viewers stay out: an export
     * takes content out of the instance, which is more than reading it in place.
     * New projects get this from the provisioner directly.
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
     * Remove the export permission; its role grants and group links cascade away.
     * The Project group itself predates this migration and stays.
     */
    public function down(): void
    {
        Permission::query()->whereIn('name', self::EXPORT_PERMISSIONS)->delete();
    }
};
