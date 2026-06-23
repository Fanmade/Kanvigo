<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Livewire\Projects\ProjectList;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('provisions an owner→admin→member tree whose access mirrors ProjectRole', function () {
    $project = Project::factory()->create();
    $roles = app(ProjectRoleProvisioner::class)->provision($project);

    $owner = User::factory()->create()->assignRole($roles['owner']);
    $admin = User::factory()->create()->assignRole($roles['admin']);
    $member = User::factory()->create()->assignRole($roles['member']);

    // Owner — everything, including the owner-only abilities.
    expect($owner->can('manage-members', $project))->toBeTrue()
        ->and($owner->can('manage-settings', $project))->toBeTrue()
        ->and($owner->can('create-tasks', $project))->toBeTrue();

    // Admin — settings and delete, but not member management.
    expect($admin->can('manage-settings', $project))->toBeTrue()
        ->and($admin->can('delete-project', $project))->toBeTrue()
        ->and($admin->can('manage-members', $project))->toBeFalse();

    // Member — contribute only.
    expect($member->can('create-tasks', $project))->toBeTrue()
        ->and($member->can('view-project', $project))->toBeTrue()
        ->and($member->can('manage-settings', $project))->toBeFalse();
});

it('isolates access to the scoping project', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $roles = app(ProjectRoleProvisioner::class)->provision($project);

    $owner = User::factory()->create()->assignRole($roles['owner']);

    expect($owner->can('manage-settings', $project))->toBeTrue()
        ->and($owner->can('manage-settings', $other))->toBeFalse();
});

it('is idempotent', function () {
    $project = Project::factory()->create();
    $provisioner = app(ProjectRoleProvisioner::class);

    $provisioner->provision($project);
    $provisioner->provision($project);

    expect(Role::query()->where('scope_type', $project->getMorphClass())->where('scope_id', $project->getKey())->count())->toBe(3);
});

it('assigns the creator the owner role when a project is created through the dialog', function () {
    $user = User::factory()->canCreateProjects()->create();

    Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->set('title', 'My Cool Project')
        ->set('short_name', 'mcp')
        ->set('description', 'A project.')
        ->call('createProject')
        ->assertHasNoErrors();

    $project = Project::where('short_name', 'MCP')->firstOrFail();

    expect($user->can('manage-members', $project))->toBeTrue()
        ->and($user->can('create-tasks', $project))->toBeTrue();
});
