<?php

use App\Enums\Permission;
use App\Livewire\Projects\ProjectList;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('registers an access-all-projects gate from the account permission', function () {
    $user = User::factory()->create();
    $user->syncPermissions([Permission::AccessAllProjects]);

    expect($user->can('access-all-projects'))->toBeTrue();
});

it('lets an access-all-projects holder view a project they do not belong to', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $user->syncPermissions([Permission::AccessAllProjects]);

    expect($user->can('view', $project))->toBeTrue();
});

it('grants visibility only — never the right to contribute or administer', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $user->syncPermissions([Permission::AccessAllProjects]);

    expect($user->can('view', $project))->toBeTrue()
        ->and($user->can('create-task', $project))->toBeFalse()
        ->and($user->can('manageSettings', $project))->toBeFalse()
        ->and($user->can('delete', $project))->toBeFalse();
});

it('shows every project in the list to an access-all-projects holder', function () {
    Project::factory()->count(3)->create();
    $user = User::factory()->create();
    $user->syncPermissions([Permission::AccessAllProjects]);

    $projects = Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->instance()->projects();

    expect($projects)->toHaveCount(3);
});

it('limits the project list to memberships without the permission', function () {
    $mine = Project::factory()->create();
    Project::factory()->count(2)->create();
    $user = User::factory()->create();
    joinProject($mine, $user, 'member');

    $projects = Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->instance()->projects();

    expect($projects->pluck('id')->all())->toBe([$mine->id]);
});
