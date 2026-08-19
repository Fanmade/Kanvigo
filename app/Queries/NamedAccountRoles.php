<?php

namespace App\Queries;

use App\Enums\Permission as AccountPermission;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Database\Eloquent\Collection;

/**
 * The named account roles: every non-system role in the global scope except the
 * single-permission roles that back the permission chips in user administration
 * (see App\Authorization\AccountPermissionProvisioner, which names each of those
 * after the permission it grants).
 *
 * Shared by the Account roles page, which administers them, and by user
 * administration, which assigns them.
 */
class NamedAccountRoles
{
    /**
     * @return Collection<int, Role>
     */
    public function handle(): Collection
    {
        return Role::query()
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->where('is_system', false)
            ->whereNotIn('name', self::chipRoleNames())
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether the role is one of the named roles rather than chip plumbing.
     */
    public function isNamed(Role $role): bool
    {
        return $role->scope_type === null
            && $role->scope_id === null
            && ! $role->is_system
            && ! in_array($role->name, self::chipRoleNames(), true);
    }

    /**
     * The names reserved by the single-permission chip roles.
     *
     * @return list<string>
     */
    public static function chipRoleNames(): array
    {
        return array_map(
            static fn (AccountPermission $permission): string => $permission->value,
            AccountPermission::cases(),
        );
    }
}
