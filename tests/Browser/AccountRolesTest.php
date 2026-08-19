<?php

use App\Enums\Permission;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Role;

it('creates a named account role and assigns it in user administration', function () {
    $admin = User::factory()->create(['name' => 'Ada Admin']);
    $admin->syncPermissions([Permission::ManageUsers, Permission::ManageAccountRoles]);
    $member = User::factory()->create(['name' => 'Bob Member']);

    $this->actingAs($admin->fresh());

    $page = visit('/admin/users');

    $page->click('@account-roles-link')
        ->assertVisible('@account-roles')
        ->click('@new-account-role')
        ->fill('@new-account-role-name', 'User manager')
        ->check('@new-account-permission-invite-users')
        ->click('@save-new-account-role')
        ->assertSeeIn('@account-role-detail-name', 'User manager')
        ->assertNoJavascriptErrors();

    $role = Role::query()->whereNull('scope_type')->where('name', 'User manager')->firstOrFail();

    visit('/admin/users')
        ->click("@role-{$member->id}-{$role->id}")
        ->assertVisible("@perm-role-{$member->id}-invite-users")
        ->assertNoJavascriptErrors();

    expect($member->fresh()->hasPermission(Permission::InviteUsers))->toBeTrue();
});

it('hides the account roles link from an admin without the permission', function () {
    $admin = User::factory()->create();
    $admin->syncPermissions([Permission::ManageUsers]);

    $this->actingAs($admin->fresh());

    visit('/admin/users')
        ->assertMissing('@account-roles-link')
        ->assertNoJavascriptErrors();
});
