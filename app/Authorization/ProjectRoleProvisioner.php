<?php

namespace App\Authorization;

use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\RoleManager;

/**
 * Provisions a project's delegated role tree (owner → admin → member) on top of
 * the delegated-permissions package. Self-bootstrapping: it seeds the permission
 * catalog and the global system role on demand.
 */
class ProjectRoleProvisioner
{
    /**
     * The project-scoped permission catalog.
     *
     * @var list<string>
     */
    public const array CATALOG = [
        'view-project', 'create-tasks', 'manage-tags',
        'manage-settings', 'delete-project',
        'manage-members', 'invite-members', 'manage-roles',
    ];

    /**
     * The permissions each seeded role holds. Each role is a strict superset of
     * the one below it, so the delegation bounds (child ⊆ parent) hold.
     *
     * @var array<string, list<string>>
     */
    public const array GRANTS = [
        'member' => ['view-project', 'create-tasks', 'manage-tags'],
        'admin' => ['view-project', 'create-tasks', 'manage-tags', 'manage-settings', 'delete-project'],
        'owner' => ['view-project', 'create-tasks', 'manage-tags', 'manage-settings', 'delete-project', 'manage-members', 'invite-members', 'manage-roles'],
    ];

    public function __construct(private readonly RoleManager $roles) {}

    /**
     * Ensure the catalog permissions exist.
     */
    public function seedCatalog(): void
    {
        foreach (self::CATALOG as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
    }

    /**
     * The global system role (created on demand).
     */
    public function systemRole(): Role
    {
        return Role::query()->firstOrCreate(
            ['is_system' => true, 'scope_type' => null, 'scope_id' => null],
            ['name' => (string) config('delegated-permissions.system.role', 'system')],
        );
    }

    /**
     * Ensure the project has its owner → admin → member tree, returning the roles
     * keyed by name. Idempotent.
     *
     * @return array<string, Role>
     */
    public function provision(Project $project): array
    {
        $this->seedCatalog();

        $existing = Role::query()
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->getKey())
            ->get()
            ->keyBy('name');

        if ($existing->has('owner') && $existing->has('admin') && $existing->has('member')) {
            return $existing->all();
        }

        $owner = $this->roles->createRole('owner', $this->systemRole(), self::GRANTS['owner'], $project);
        $admin = $this->roles->createRole('admin', $owner, self::GRANTS['admin']);
        $member = $this->roles->createRole('member', $admin, self::GRANTS['member']);

        return ['owner' => $owner, 'admin' => $admin, 'member' => $member];
    }

    /**
     * The project's role of the given name (owner|admin|member), provisioning if needed.
     */
    public function roleFor(Project $project, string $name): Role
    {
        return $this->provision($project)[$name];
    }

    /**
     * Sync a user's role for a project to a single project role (owner|admin|
     * member, or a custom role name), or remove them from the project's tree
     * when $roleName is null. A user holds at most one project-scoped role.
     */
    public function syncMember(Project $project, User $user, ?string $roleName): void
    {
        $roles = $this->provision($project);

        $user->roles()
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->getKey())
            ->get()
            ->each(static fn (Role $role) => $user->removeRole($role));

        if ($roleName !== null) {
            $user->assignRole($roles[$roleName]);
        }
    }
}
