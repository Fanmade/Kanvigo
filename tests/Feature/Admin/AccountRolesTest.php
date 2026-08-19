<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Permission;
use App\Livewire\Admin\AccountRoles;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Permission as PackagePermission;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\PermissionResolver;
use Fanmade\DelegatedPermissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * An account administrator who may manage named global roles and holds every
 * account permission, so the picker offers the whole catalog. Roles may only
 * carry permissions their author holds (KAN-559).
 */
function accountRoleAdmin(): User
{
    $user = User::factory()->create();
    $user->syncPermissions(Permission::cases());

    return $user->fresh();
}

/**
 * A named global role holding the given account permissions.
 *
 * @param  list<Permission>  $permissions
 */
function namedAccountRole(string $name, array $permissions): Role
{
    return app(RoleManager::class)->createRole(
        $name,
        app(ProjectRoleProvisioner::class)->systemRole(),
        array_map(static fn (Permission $permission): string => $permission->value, $permissions),
    );
}

it('forbids the page to a user without manage-account-roles', function () {
    $user = User::factory()->create();
    $user->syncPermissions([Permission::ManageUsers]);

    Livewire::actingAs($user->fresh())
        ->test(AccountRoles::class)
        ->assertForbidden();
});

it('lists named global roles and hides the single-permission chip roles', function () {
    $admin = accountRoleAdmin();
    namedAccountRole('User manager', [Permission::InviteUsers, Permission::ManageUsers]);

    $names = Livewire::actingAs($admin)
        ->test(AccountRoles::class)
        ->assertOk()
        ->instance()->roles()->pluck('name');

    expect($names)->toContain('User manager')
        ->and($names)->not->toContain('manage-users', 'invite-users', 'manage-account-roles');
});

it('creates a top-level named role under the system root', function () {
    $admin = accountRoleAdmin();
    $inviteId = PackagePermission::query()->where('name', Permission::InviteUsers->value)->value('id');

    $component = Livewire::actingAs($admin)
        ->test(AccountRoles::class)
        ->call('startCreate')
        ->set('newName', 'Recruiter')
        ->set('newPermissionIds', [$inviteId])
        ->call('createRole')
        ->assertHasNoErrors();

    $role = Role::query()->whereNull('scope_type')->where('name', 'Recruiter')->firstOrFail();

    expect(app(PermissionResolver::class)->permissionsFor($role)->all())->toBe([Permission::InviteUsers->value])
        ->and($component->instance()->selectedRole()->id)->toBe($role->id);

    $holder = User::factory()->create()->assignRole($role);
    expect($holder->hasPermission(Permission::InviteUsers))->toBeTrue()
        ->and($holder->hasPermission(Permission::ManageUsers))->toBeFalse();
});

it('rejects a duplicate role name', function () {
    $admin = accountRoleAdmin();
    namedAccountRole('Recruiter', [Permission::InviteUsers]);

    Livewire::actingAs($admin)
        ->test(AccountRoles::class)
        ->call('startCreate')
        ->set('newName', 'Recruiter')
        ->call('createRole')
        ->assertHasErrors('newName');
});

it('bounds a child role by its parent', function () {
    $admin = accountRoleAdmin();
    $parent = namedAccountRole('Recruiter', [Permission::InviteUsers]);
    $manageUsers = PackagePermission::query()->where('name', Permission::ManageUsers->value)->value('id');
    $inviteUsers = PackagePermission::query()->where('name', Permission::InviteUsers->value)->value('id');

    $component = Livewire::actingAs($admin)
        ->test(AccountRoles::class)
        ->call('startCreate', $parent->id);

    expect($component->instance()->createAllowedPermissions())->toBe([Permission::InviteUsers->value]);

    // manage-users is outside the parent's set and is dropped rather than granted.
    $component->set('newName', 'Junior recruiter')
        ->set('newPermissionIds', [$inviteUsers, $manageUsers])
        ->call('createRole')
        ->assertHasNoErrors();

    $child = Role::query()->where('name', 'Junior recruiter')->firstOrFail();

    expect($child->parent_id)->toBe($parent->id)
        ->and(app(PermissionResolver::class)->permissionsFor($child)->all())->toBe([Permission::InviteUsers->value]);
});

it('renames a role and edits its permissions', function () {
    $admin = accountRoleAdmin();
    $role = namedAccountRole('User manager', [Permission::InviteUsers]);
    $manageUsers = PackagePermission::query()->where('name', Permission::ManageUsers->value)->value('id');

    Livewire::actingAs($admin)
        ->test(AccountRoles::class)
        ->call('selectRole', $role->id)
        ->call('startEdit')
        ->set('editName', 'People admin')
        ->set('editDescription', 'Runs the account.')
        ->set('editPermissionIds', [$manageUsers])
        ->call('saveRole')
        ->assertHasNoErrors();

    expect($role->fresh()->name)->toBe('People admin')
        ->and($role->fresh()->description)->toBe('Runs the account.')
        ->and(app(PermissionResolver::class)->permissionsFor($role->fresh())->all())->toBe([Permission::ManageUsers->value]);
});

it('deletes a role and drops it from its holders', function () {
    $admin = accountRoleAdmin();
    $role = namedAccountRole('User manager', [Permission::ManageUsers]);
    $holder = User::factory()->create()->assignRole($role);

    Livewire::actingAs($admin)
        ->test(AccountRoles::class)
        ->call('selectRole', $role->id)
        ->call('deleteRole');

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and($holder->fresh()->hasPermission(Permission::ManageUsers))->toBeFalse();
});

it('counts role members without a query per role', function () {
    $admin = accountRoleAdmin();

    $countQueries = function (int $extraRoles) use ($admin): int {
        for ($i = 0; $i < $extraRoles; $i++) {
            $role = namedAccountRole('Role '.uniqid(), [Permission::InviteUsers]);
            User::factory()->create()->assignRole($role);
        }

        $component = Livewire::actingAs($admin)->test(AccountRoles::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->instance()->memberCounts();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($countQueries(10))->toBeLessThanOrEqual($countQueries(1));
});

it('offers only the account permissions the administrator holds', function () {
    $admin = User::factory()->create();
    $admin->syncPermissions([Permission::ManageAccountRoles, Permission::InviteUsers]);

    $offered = collect(Livewire::actingAs($admin->fresh())
        ->test(AccountRoles::class)
        ->instance()->catalogGroups())
        ->flatten()
        ->pluck('name');

    expect($offered)->toContain(Permission::InviteUsers->value)
        ->and($offered)->not->toContain(Permission::ManageUsers->value, Permission::AccessAllProjects->value);
});

it('refuses to put a permission the administrator lacks into a new role', function () {
    $admin = User::factory()->create();
    $admin->syncPermissions([Permission::ManageAccountRoles, Permission::InviteUsers]);

    $ids = PackagePermission::query()
        ->whereIn('name', [Permission::InviteUsers->value, Permission::ManageUsers->value])
        ->pluck('id')
        ->all();

    Livewire::actingAs($admin->fresh())
        ->test(AccountRoles::class)
        ->call('startCreate')
        ->set('newName', 'Recruiter')
        ->set('newPermissionIds', $ids)
        ->call('createRole')
        ->assertHasNoErrors();

    $role = Role::query()->whereNull('scope_type')->where('name', 'Recruiter')->firstOrFail();

    expect(app(PermissionResolver::class)->permissionsFor($role)->all())->toBe([Permission::InviteUsers->value]);
});

it('leaves a permission the administrator lacks untouched when saving a role', function () {
    $admin = User::factory()->create();
    $admin->syncPermissions([Permission::ManageAccountRoles, Permission::InviteUsers]);

    $role = namedAccountRole('User manager', [Permission::InviteUsers, Permission::ManageUsers]);

    $component = Livewire::actingAs($admin->fresh())
        ->test(AccountRoles::class)
        ->call('selectRole', $role->id)
        ->call('startEdit');

    // The form names what it cannot touch, and clearing it changes nothing.
    expect($component->instance()->beyondReachPermissions())->toBe([Permission::ManageUsers->label()]);

    $component->set('editPermissionIds', [])
        ->call('saveRole')
        ->assertHasNoErrors();

    expect(app(PermissionResolver::class)->permissionsFor($role->fresh())->all())
        ->toBe([Permission::ManageUsers->value]);
});

it('offers an administrator only their own permission when they hold just one', function () {
    $admin = User::factory()->create();
    $admin->syncPermissions([Permission::ManageAccountRoles]);

    $offered = collect(Livewire::actingAs($admin->fresh())
        ->test(AccountRoles::class)
        ->instance()->catalogGroups())
        ->flatten()
        ->pluck('name');

    // Only manage-account-roles itself — a role granting it is legitimate.
    expect($offered->all())->toBe([Permission::ManageAccountRoles->value]);
});
