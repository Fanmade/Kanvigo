<?php

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('backs each permission with its gate name and exposes a label', function () {
    expect(Permission::CreateProjects->value)->toBe('create-projects')
        ->and(Permission::InviteUsers->value)->toBe('invite-users');

    foreach (Permission::cases() as $permission) {
        expect($permission->label())->toBeString()->not->toBe('');
    }
});

it('does not mass-assign user_id on a permission grant', function () {
    $grant = new UserPermission(['user_id' => 999, 'permission' => Permission::ManageUsers]);

    expect($grant->user_id)->toBeNull()
        ->and($grant->permission)->toBe(Permission::ManageUsers);
});

it('denies a permission the user has not been granted', function () {
    $user = User::factory()->create();

    expect($user->hasPermission(Permission::CreateProjects))->toBeFalse();
});

it('grants a permission after it is synced and persists it', function () {
    $user = User::factory()->create();

    $user->syncPermissions([Permission::CreateProjects]);

    expect($user->hasPermission(Permission::CreateProjects))->toBeTrue();
    $this->assertDatabaseHas('user_permissions', [
        'user_id' => $user->id,
        'permission' => 'create-projects',
    ]);
});

it('revokes permissions that are not part of the synced set', function () {
    $user = User::factory()->withPermission(Permission::CreateProjects, Permission::InviteUsers)->create();

    $user->syncPermissions([Permission::InviteUsers]);

    expect($user->hasPermission(Permission::InviteUsers))->toBeTrue()
        ->and($user->hasPermission(Permission::CreateProjects))->toBeFalse();
    $this->assertDatabaseMissing('user_permissions', [
        'user_id' => $user->id,
        'permission' => 'create-projects',
    ]);
});

it('is idempotent and never duplicates a grant', function () {
    $user = User::factory()->create();

    $user->syncPermissions([Permission::CreateProjects]);
    $user->syncPermissions([Permission::CreateProjects]);

    expect($user->permissions()->where('permission', 'create-projects')->count())->toBe(1);
});

it('accepts string values as well as enum instances', function () {
    $user = User::factory()->create();

    $user->syncPermissions(['invite-users']);

    expect($user->hasPermission(Permission::InviteUsers))->toBeTrue();
});

it('filters users by a granted permission via the scope', function () {
    $granted = User::factory()->canInviteUsers()->create();
    User::factory()->create();

    $results = User::wherePermission(Permission::InviteUsers)->get();

    expect($results->pluck('id'))->toContain($granted->id)->toHaveCount(1);
});

it('registers a gate for every permission case', function () {
    $user = User::factory()->canCreateProjects()->create();

    expect(Gate::forUser($user)->allows('create-projects'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('invite-users'))->toBeFalse();
});

it('gates project creation behind the granted permission', function () {
    $allowed = User::factory()->canCreateProjects()->create();
    $denied = User::factory()->create();

    expect($allowed->can('create', Project::class))->toBeTrue()
        ->and($denied->can('create', Project::class))->toBeFalse();
});

it('casts the stored permission column back to the enum', function () {
    $user = User::factory()->canInviteUsers()->create();

    $grant = $user->permissions()->sole();

    expect($grant)->toBeInstanceOf(UserPermission::class)
        ->and($grant->permission)->toBe(Permission::InviteUsers);
});
