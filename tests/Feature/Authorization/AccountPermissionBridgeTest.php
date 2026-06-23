<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('grants every account permission to a system-role holder as break-glass', function () {
    $system = User::factory()->create()->assignRole(
        app(ProjectRoleProvisioner::class)->systemRole()
    );

    foreach (Permission::cases() as $permission) {
        expect($system->hasPermission($permission))->toBeTrue()
            ->and($system->can($permission->value))->toBeTrue();
    }
});

it('still honours explicit per-user grants from user_permissions', function () {
    $user = User::factory()->canManageUsers()->create();

    expect($user->hasPermission(Permission::ManageUsers))->toBeTrue()
        ->and($user->hasPermission(Permission::CreateProjects))->toBeFalse();
});

it('denies a user with neither a global role nor an explicit grant', function () {
    $user = User::factory()->create();

    foreach (Permission::cases() as $permission) {
        expect($user->hasPermission($permission))->toBeFalse();
    }
});
