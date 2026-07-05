<?php

namespace App\Authorization;

use App\Enums\Permission as AccountPermission;
use App\Models\User;
use App\Support\Facades\Audit;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\RoleManager;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * Provisions the global (account-level) permission layer on the
 * delegated-permissions package: one global role per {@see AccountPermission},
 * each a child of the system role granting exactly that permission. Holding the
 * matching global role is how an account permission is granted — the single store
 * now that the legacy `user_permissions` pivot is retired (KAN-385).
 *
 * The system-role break-glass (it implicitly holds every permission) is
 * unaffected: a system-role holder still satisfies every account permission.
 */
readonly class AccountPermissionProvisioner
{
    public function __construct(
        private RoleManager $roles,
        private ProjectRoleProvisioner $projectRoles,
    ) {}

    /**
     * Ensure the account-permission catalog and a global role per permission
     * exist, returning the roles keyed by permission value. Idempotent, and
     * tolerant of a partial tree (it fills in any missing role).
     *
     * @return array<string, Role>
     */
    public function provision(): array
    {
        $this->seedCatalog();

        $system = $this->projectRoles->systemRole();

        $existing = Role::query()
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->where('is_system', false)
            ->get()
            ->keyBy('name');

        $roles = [];

        foreach (AccountPermission::cases() as $permission) {
            $roles[$permission->value] = $existing->get($permission->value)
                ?? $this->roles->createRole($permission->value, $system, [$permission->value]);
        }

        return $roles;
    }

    /**
     * Ensure each account permission exists in the package catalog. Cheap on the
     * common path: a single count query when everything is already seeded.
     */
    public function seedCatalog(): void
    {
        $names = array_map(static fn (AccountPermission $permission): string => $permission->value, AccountPermission::cases());

        if (Permission::query()->whereIn('name', $names)->count() === count($names)) {
            return;
        }

        foreach ($names as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
    }

    /**
     * Grant a single account permission to the user. Idempotent.
     */
    public function grant(User $user, AccountPermission $permission): void
    {
        $this->apply($user, $this->provision()[$permission->value], granted: true);
    }

    /**
     * Revoke a single account permission from the user. A no-op if not held.
     */
    public function revoke(User $user, AccountPermission $permission): void
    {
        $this->apply($user, $this->provision()[$permission->value], granted: false);
    }

    /**
     * Replace the user's account permissions with the given set, assigning the
     * matching global roles and removing the rest.
     *
     * @param  array<int, AccountPermission>  $permissions
     */
    public function sync(User $user, array $permissions): void
    {
        $wanted = collect($permissions)
            ->map(static fn (AccountPermission $permission): string => $permission->value)
            ->unique();

        foreach ($this->provision() as $name => $role) {
            $this->apply($user, $role, granted: $wanted->contains($name));
        }
    }

    /**
     * Assign or remove one account-permission role, recording an audit event
     * when the grant actually changed hands (idempotent re-grants and re-revokes
     * stay silent, so the trail reflects real permission changes only).
     */
    protected function apply(User $user, Role $role, bool $granted): void
    {
        $held = $user->roles()->whereKey($role->getKey())->exists();

        if ($granted) {
            $user->assignRole($role);
        } else {
            $user->removeRole($role);
        }

        if ($held === $granted) {
            return;
        }

        Audit::record(AuditEvent::make($granted ? 'permission_granted' : 'permission_revoked', AuditCategory::Authz)
            ->withSubject($user->getMorphClass(), $user->getKey())
            ->withMetadata(['permission' => $role->name]));
    }
}
