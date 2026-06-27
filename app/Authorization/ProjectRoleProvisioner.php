<?php

namespace App\Authorization;

use App\Authorization\Exceptions\OwnerAlreadyAssigned;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\DelegatedPermissions;
use Fanmade\DelegatedPermissions\Exceptions\RoleLimitExceeded;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\PermissionGroup;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\RoleManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Provisions a project's delegated role tree (owner → admin → member → viewer)
 * on top of the delegated-permissions package. Self-bootstrapping: it seeds the
 * permission catalog, its groups and the global system role on demand.
 */
class ProjectRoleProvisioner
{
    /**
     * The project-scoped permission catalog, grouped for the management UI. The
     * group order and contents drive how the permission picker is laid out; the
     * flattened set is the catalog every project role is bounded by.
     *
     * @var array<string, list<string>>
     */
    public const array GROUPS = [
        'Project' => ['view-project', 'manage-settings', 'delete-project', 'view-activity-log'],
        'Members & roles' => ['manage-members', 'invite-members', 'manage-roles'],
        'Tasks' => ['create-task', 'edit-task', 'delete-task', 'close-task', 'cancel-task', 'archive-task', 'manage-dependencies'],
        'Tags' => ['manage-tags', 'tag-tasks'],
        'Attachments' => ['manage-attachments', 'delete-attachment'],
        'Comments' => ['create-comment', 'moderate-comments'],
    ];

    /**
     * The flat project-scoped permission catalog (every permission across the
     * groups). The owner role holds exactly this set.
     *
     * @var list<string>
     */
    public const array CATALOG = [
        'view-project', 'manage-settings', 'delete-project', 'view-activity-log',
        'manage-members', 'invite-members', 'manage-roles',
        'create-task', 'edit-task', 'delete-task', 'close-task', 'cancel-task', 'archive-task', 'manage-dependencies',
        'manage-tags', 'tag-tasks',
        'manage-attachments', 'delete-attachment',
        'create-comment', 'moderate-comments',
    ];

    /**
     * The permissions each seeded role holds. Each role is a strict superset of
     * the one below it (owner ⊇ admin ⊇ member ⊇ viewer), so the delegation
     * bounds (child ⊆ parent) hold. These mirror today's coarse behaviour:
     * members contribute, admins also govern settings, owners also manage people
     * and roles; viewer is read-only.
     *
     * @var array<string, list<string>>
     */
    public const array GRANTS = [
        'viewer' => ['view-project', 'view-activity-log'],
        'member' => [
            'view-project', 'view-activity-log',
            'create-task', 'edit-task', 'delete-task', 'close-task', 'cancel-task', 'archive-task', 'manage-dependencies',
            'manage-tags', 'tag-tasks',
            'manage-attachments', 'delete-attachment',
            'create-comment',
        ],
        'admin' => [
            'view-project', 'view-activity-log',
            'create-task', 'edit-task', 'delete-task', 'close-task', 'cancel-task', 'archive-task', 'manage-dependencies',
            'manage-tags', 'tag-tasks',
            'manage-attachments', 'delete-attachment',
            'create-comment', 'moderate-comments',
            'manage-settings', 'delete-project',
        ],
        'owner' => self::CATALOG,
    ];

    /**
     * One-click role presets a manager can instantiate under a chosen parent.
     * Each is intersected with the parent's effective permissions at creation
     * time, so a preset never escalates past its delegation bound — it is a
     * convenient starting set, not a guaranteed one.
     *
     * @var array<string, list<string>>
     */
    public const array TEMPLATES = [
        'Product Owner' => [
            'view-project', 'view-activity-log', 'manage-settings',
            'create-task', 'edit-task', 'close-task', 'cancel-task', 'archive-task', 'manage-dependencies',
            'manage-tags', 'tag-tasks',
            'create-comment', 'moderate-comments',
        ],
        'Designer' => [
            'view-project', 'view-activity-log',
            'create-task', 'edit-task', 'manage-dependencies',
            'tag-tasks',
            'manage-attachments', 'delete-attachment',
            'create-comment',
        ],
        'Developer' => [
            'view-project', 'view-activity-log',
            'create-task', 'edit-task', 'close-task', 'manage-dependencies',
            'tag-tasks',
            'manage-attachments',
            'create-comment',
        ],
        'Reviewer' => [
            'view-project', 'view-activity-log',
            'edit-task', 'close-task', 'cancel-task',
            'tag-tasks',
            'create-comment', 'moderate-comments',
        ],
    ];

    public function __construct(private readonly RoleManager $roles) {}

    /**
     * Ensure the catalog permissions and their groups exist. Cheap on the common
     * path: when everything is already seeded it costs two count queries.
     */
    public function seedCatalog(): void
    {
        $existing = Permission::query()->whereIn('name', self::CATALOG)->pluck('id', 'name');

        if ($existing->count() === count(self::CATALOG)
            && PermissionGroup::query()->whereIn('name', array_keys(self::GROUPS))->count() === count(self::GROUPS)) {
            return;
        }

        foreach (self::CATALOG as $name) {
            $existing[$name] ??= Permission::query()->create(['name' => $name])->getKey();
        }

        $this->seedGroups($existing);
    }

    /**
     * Ensure each permission group exists and holds exactly its catalog members.
     *
     * @param  Collection<string, int>  $permissionIds  permission name => id
     */
    protected function seedGroups(Collection $permissionIds): void
    {
        foreach (self::GROUPS as $groupName => $names) {
            $group = PermissionGroup::query()->firstOrCreate(['name' => $groupName]);
            $group->permissions()->sync(array_map(static fn (string $name): int => $permissionIds[$name], $names));
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
     * Ensure the project has its owner → admin → member → viewer tree, returning
     * the roles keyed by name. Idempotent, and tolerant of a partial tree (it
     * fills in any missing role rather than starting over).
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

        if ($existing->has('owner') && $existing->has('admin') && $existing->has('member') && $existing->has('viewer')) {
            return $existing->all();
        }

        $owner = $existing['owner'] ?? $this->roles->createRole('owner', $this->systemRole(), self::GRANTS['owner'], $project);
        $admin = $existing['admin'] ?? $this->roles->createRole('admin', $owner, self::GRANTS['admin']);
        $member = $existing['member'] ?? $this->roles->createRole('member', $admin, self::GRANTS['member']);
        $viewer = $existing['viewer'] ?? $this->roles->createRole('viewer', $member, self::GRANTS['viewer']);

        return ['owner' => $owner, 'admin' => $admin, 'member' => $member, 'viewer' => $viewer];
    }

    /**
     * The project's role of the given name (owner|admin|member|viewer, or a
     * custom role), provisioning the base tree if needed.
     */
    public function roleFor(Project $project, string $name): Role
    {
        return $this->provision($project)[$name];
    }

    /**
     * Sync a user's role for a project to a single project role (owner|admin|
     * member, or a custom role name), or remove them from the project's tree
     * when $roleName is null. Replaces every project-scoped role the user holds,
     * so it is the right tool for one-role flows — owner provisioning,
     * invite-accept and the "add as member" path. Use {@see addRole()} /
     * {@see removeRole()} for the multi-role member panel.
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

    /**
     * Add a project role (owner|admin|member|viewer, or a custom role name) to a
     * user without disturbing the roles they already hold. Idempotent. Honours
     * the configured per-scope role cap (the package throws
     * {@see RoleLimitExceeded} when it
     * would be exceeded), and the one-owner-per-project rule
     * ({@see OwnerAlreadyAssigned} when a different user already owns it).
     */
    public function addRole(Project $project, User $user, string $roleName): void
    {
        $role = $this->projectRole($project, $roleName);

        if ($role->name === 'owner' && $this->hasOtherOwner($project, $user)) {
            throw OwnerAlreadyAssigned::for($project);
        }

        $user->assignRole($role);
    }

    /**
     * Remove a single project role from a user, leaving their other project
     * roles intact. A no-op if they do not hold it.
     */
    public function removeRole(Project $project, User $user, string $roleName): void
    {
        $role = $this->projectRole($project, $roleName);

        $user->removeRole($role);
    }

    /**
     * The project-scoped role of the given name — a base role (provisioning the
     * tree if needed) or a custom one already defined on the project.
     */
    protected function projectRole(Project $project, string $roleName): Role
    {
        $base = $this->provision($project);

        return $base[$roleName] ?? Role::query()
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->getKey())
            ->where('name', $roleName)
            ->firstOrFail();
    }

    /**
     * Whether a user other than the given one already holds the owner role on
     * the project.
     */
    protected function hasOtherOwner(Project $project, User $user): bool
    {
        $owner = $this->provision($project)['owner'];

        return DB::table(DelegatedPermissions::table('role_assignments'))
            ->where('role_id', $owner->getKey())
            ->where(static function ($query) use ($user): void {
                $query->where('authorizable_type', '!=', $user->getMorphClass())
                    ->orWhere('authorizable_id', '!=', $user->getKey());
            })
            ->exists();
    }
}
