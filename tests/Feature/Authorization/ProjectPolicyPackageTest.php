<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves ProjectPolicy through package roles without a legacy pivot row', function () {
    $project = Project::factory()->create();
    $roles = app(ProjectRoleProvisioner::class)->provision($project);

    $admin = User::factory()->create()->assignRole($roles['admin']);

    // No project_user pivot exists — access comes purely from the package.
    expect($project->members()->whereKey($admin->id)->exists())->toBeFalse();

    expect($admin->can('view', $project))->toBeTrue()
        ->and($admin->can('create-task', $project))->toBeTrue()
        ->and($admin->can('manageSettings', $project))->toBeTrue()
        ->and($admin->can('delete', $project))->toBeTrue()
        ->and($admin->can('manageMembers', $project))->toBeFalse();

    expect($project->roleNameFor($admin))->toBe('admin')
        ->and($project->isOwner($admin))->toBeFalse();
});

it('grants the matching permissions when a member is synced up to admin', function () {
    $project = Project::factory()->create();
    $member = User::factory()->create()->assignRole(
        app(ProjectRoleProvisioner::class)->roleFor($project, 'member')
    );

    app(ProjectRoleProvisioner::class)->syncMember($project, $member, 'admin');

    expect($member->can('manageSettings', $project))->toBeTrue()
        ->and($project->roleNameFor($member))->toBe('admin');
});

it('drops all project access when a member is unsynced', function () {
    $project = Project::factory()->create();
    $member = User::factory()->create()->assignRole(
        app(ProjectRoleProvisioner::class)->roleFor($project, 'member')
    );

    app(ProjectRoleProvisioner::class)->syncMember($project, $member, null);

    expect($member->can('view', $project))->toBeFalse()
        ->and($project->roleNameFor($member))->toBeNull();
});
