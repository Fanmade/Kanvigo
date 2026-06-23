<?php

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ranks roles by privilege', function () {
    expect(ProjectRole::Owner->atLeast(ProjectRole::Admin))->toBeTrue()
        ->and(ProjectRole::Owner->atLeast(ProjectRole::Owner))->toBeTrue()
        ->and(ProjectRole::Admin->atLeast(ProjectRole::Owner))->toBeFalse()
        ->and(ProjectRole::Admin->atLeast(ProjectRole::Member))->toBeTrue()
        ->and(ProjectRole::Member->atLeast(ProjectRole::Admin))->toBeFalse();
});

it('exposes each member\'s role through the pivot', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $stranger = User::factory()->create();

    $project = Project::factory()->create();
    $project->members()->attach($owner, ['role' => ProjectRole::Owner->value]);
    $project->members()->attach($admin, ['role' => ProjectRole::Admin->value]);
    $project->members()->attach($member, ['role' => ProjectRole::Member->value]);

    expect($project->roleFor($owner))->toBe(ProjectRole::Owner)
        ->and($project->roleFor($admin))->toBe(ProjectRole::Admin)
        ->and($project->roleFor($member))->toBe(ProjectRole::Member)
        ->and($project->roleFor($stranger))->toBeNull();
});

it('treats owner and admin as admin, but only owner as owner', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $project = Project::factory()->create();
    $project->members()->attach($owner, ['role' => ProjectRole::Owner->value]);
    $project->members()->attach($admin, ['role' => ProjectRole::Admin->value]);
    $project->members()->attach($member, ['role' => ProjectRole::Member->value]);

    expect($project->isAdmin($owner))->toBeTrue()
        ->and($project->isAdmin($admin))->toBeTrue()
        ->and($project->isAdmin($member))->toBeFalse()
        ->and($project->isOwner($owner))->toBeTrue()
        ->and($project->isOwner($admin))->toBeFalse();
});

it('defaults a member attached without a role to the member role', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);

    expect($project->roleFor($user))->toBe(ProjectRole::Member)
        ->and($project->isAdmin($user))->toBeFalse();
});

it('creates a project owned by a user through the factory', function () {
    $owner = User::factory()->create();

    $project = Project::factory()->withOwner($owner)->create();

    expect($project->isOwner($owner))->toBeTrue();
});
