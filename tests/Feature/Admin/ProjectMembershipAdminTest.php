<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\ProjectRole;
use App\Livewire\Admin\UserManagement;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * An account admin who also holds the global system role — the break-glass path
 * for managing any project's roster from the admin panel (KAN-240).
 */
function systemAdmin(): User
{
    return User::factory()->canManageUsers()->create()
        ->assignRole(app(ProjectRoleProvisioner::class)->systemRole());
}

it('lets a system admin add a user to a project as a member', function () {
    $admin = systemAdmin();
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('manageProjects', $user->id)
        ->call('addUserToProject', $project->id);

    expect($project->roleFor($user))->toBe(ProjectRole::Member);
});

it('lets a system admin change a user\'s project role and remove them', function () {
    $admin = systemAdmin();
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user, ['role' => ProjectRole::Member->value]);

    $component = Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('manageProjects', $user->id);

    $component->call('setUserProjectRole', $project->id, ProjectRole::Admin->value);
    expect($project->roleFor($user))->toBe(ProjectRole::Admin);

    $component->call('removeUserFromProject', $project->id);
    expect($project->members()->whereKey($user->id)->exists())->toBeFalse();
});

it('does not let a system admin re-role or remove a project owner', function () {
    $admin = systemAdmin();
    $owner = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($owner, ['role' => ProjectRole::Owner->value]);

    $component = Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('manageProjects', $owner->id);

    $component->call('setUserProjectRole', $project->id, ProjectRole::Member->value);
    $component->call('removeUserFromProject', $project->id);

    expect($project->roleFor($owner))->toBe(ProjectRole::Owner)
        ->and($project->members()->whereKey($owner->id)->exists())->toBeTrue();
});

it('forbids a plain manage-users admin from managing an arbitrary project roster', function () {
    $admin = User::factory()->canManageUsers()->create(); // no system role, not a member
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('manageProjects', $user->id)
        ->call('addUserToProject', $project->id)
        ->assertForbidden();

    expect($project->roleFor($user))->toBeNull();
});

it('exposes the managed user\'s roles per project', function () {
    $admin = User::factory()->canManageUsers()->create();
    $user = User::factory()->create();
    $adminProject = Project::factory()->create();
    $memberProject = Project::factory()->create();
    Project::factory()->create(); // user is not in this one
    $adminProject->members()->attach($user, ['role' => ProjectRole::Admin->value]);
    $memberProject->members()->attach($user, ['role' => ProjectRole::Member->value]);

    $roles = Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('manageProjects', $user->id)
        ->instance()->managedUserRoles();

    expect($roles)->toBe([
        $adminProject->id => 'admin',
        $memberProject->id => 'member',
    ]);
});

it('forbids a non-admin from opening user administration', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UserManagement::class)
        ->assertForbidden();
});
