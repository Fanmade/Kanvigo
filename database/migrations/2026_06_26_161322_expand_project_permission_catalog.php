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
     * The eight permissions that existed before this migration (create-tasks is
     * renamed to create-task here). Everything else in the catalog is new.
     *
     * @var list<string>
     */
    private const array ORIGINAL = [
        'view-project', 'create-task', 'manage-tags', 'manage-settings',
        'delete-project', 'manage-members', 'invite-members', 'manage-roles',
    ];

    /**
     * Grow the project permission catalog (KAN-309): rename create-tasks →
     * create-task, seed the new permissions and their groups, then re-grant the
     * recomputed permission sets to every existing project role tree and add the
     * new read-only viewer role. Role grants reference permissions by id, so the
     * rename repoints existing grants automatically.
     */
    public function up(): void
    {
        // Rename in place — every role that held it keeps the grant (same id).
        Permission::query()->where('name', 'create-tasks')->update(['name' => 'create-task']);

        app(ProjectRoleProvisioner::class)->seedCatalog();

        $permissionIds = Permission::query()
            ->whereIn('name', ProjectPermission::names())
            ->pluck('id', 'name');

        $idsFor = static fn (string $role): array => array_map(
            static fn (string $name): int => $permissionIds[$name],
            ProjectRoleProvisioner::grants()[$role],
        );

        Project::query()->each(function (Project $project) use ($idsFor): void {
            $roles = Role::query()
                ->where('scope_type', $project->getMorphClass())
                ->where('scope_id', $project->getKey())
                ->get()
                ->keyBy('name');

            // Recompute the base roles' permission sets in place (direct sync —
            // the sets are consistent supersets, so no per-grant bound check).
            foreach (['owner', 'admin', 'member'] as $name) {
                $roles->get($name)?->permissions()->sync($idsFor($name));
            }

            // Backfill the viewer role under member for trees that predate it.
            $member = $roles->get('member');

            if ($member !== null && ! $roles->has('viewer')) {
                Role::query()->create([
                    'name' => 'viewer',
                    'parent_id' => $member->getKey(),
                    'scope_type' => $project->getMorphClass(),
                    'scope_id' => $project->getKey(),
                ])->permissions()->sync($idsFor('viewer'));
            }
        });
    }

    /**
     * Reverse the migrations: drop the viewer roles, rename create-task back, and
     * remove the new permissions (their grants and group links cascade away) and
     * the groups. Base roles fall back to the surviving original permissions.
     */
    public function down(): void
    {
        Role::query()->where('name', 'viewer')->whereNotNull('scope_id')->delete();

        Permission::query()->where('name', 'create-task')->update(['name' => 'create-tasks']);

        Permission::query()
            ->whereIn('name', array_values(array_diff(ProjectPermission::names(), self::ORIGINAL)))
            ->delete();

        PermissionGroup::query()
            ->whereIn('name', array_keys(ProjectPermission::groups()))
            ->delete();
    }
};
