<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Permission;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;

/**
 * Every permission name the package's Gate::before can match an ability against
 * and auto-grant: the project-scoped catalog plus the account-level permissions.
 *
 * @return list<string>
 */
function grantablePermissionNames(): array
{
    return [
        ...ProjectRoleProvisioner::CATALOG,
        ...array_map(static fn (Permission $permission): string => $permission->value, Permission::cases()),
    ];
}

it('keeps policy ability names from colliding with a grantable permission', function () {
    $grantable = grantablePermissionNames();

    // The delegated-permissions Gate::before grants any ability whose name equals a
    // permission the user holds in the scope (and never denies), short-circuiting
    // the policy. An ability named after a catalog permission would therefore be
    // auto-granted and its method body — including any added restriction — skipped.
    foreach ([ProjectPolicy::class, TaskPolicy::class] as $policy) {
        $abilities = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $collisions = array_values(array_intersect($abilities, $grantable));

        expect($collisions)->toBe(
            [],
            "{$policy} ability names collide with grantable permissions, so Gate::before would bypass them: ".implode(', ', $collisions),
        );
    }
});
