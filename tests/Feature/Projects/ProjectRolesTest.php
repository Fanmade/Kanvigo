<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Livewire\Projects\ProjectRoles;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function projectOwner(Project $project): User
{
    return User::factory()->create()->assignRole(
        app(ProjectRoleProvisioner::class)->roleFor($project, 'owner')
    );
}

it('lists the seeded roles for an owner', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);

    $roles = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->instance()->roles()->pluck('name');

    expect($roles)->toContain('owner', 'admin', 'member');
});

it('lets an owner define a custom role bounded by the project permissions', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $createTasks = Permission::query()->where('name', 'create-tasks')->value('id');

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->set('name', 'Triager')
        ->set('permissionIds', [$createTasks])
        ->call('createRole')
        ->assertHasNoErrors();

    $role = Role::query()->where('scope_id', $project->id)->where('name', 'Triager')->first();
    expect($role)->not->toBeNull();

    $triager = User::factory()->create()->assignRole($role);
    expect($triager->can('create-tasks', $project))->toBeTrue()
        ->and($triager->can('manage-settings', $project))->toBeFalse();
});

it('rejects a duplicate role name', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->set('name', 'admin')
        ->call('createRole')
        ->assertHasErrors('name');
});

it('will not delete a seeded base role', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $admin = Role::query()->where('scope_id', $project->id)->where('name', 'admin')->firstOrFail();

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->call('deleteRole', $admin->id);

    expect(Role::query()->whereKey($admin->id)->exists())->toBeTrue();
});

it('deletes a custom role', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->set('name', 'Triager')
        ->call('createRole');

    $role = Role::query()->where('scope_id', $project->id)->where('name', 'Triager')->firstOrFail();

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->call('deleteRole', $role->id);

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

it('forbids a non-owner from managing roles', function () {
    $project = Project::factory()->create();
    $member = User::factory()->create()->assignRole(
        app(ProjectRoleProvisioner::class)->roleFor($project, 'member')
    );

    Livewire::actingAs($member)
        ->test(ProjectRoles::class, ['project' => $project])
        ->assertForbidden();
});
