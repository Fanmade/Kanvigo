<?php

use App\Actions\AddProjectMember;
use App\Actions\RemoveProjectMember;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds a member with the base role so the pivot and role stay together', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    app(AddProjectMember::class)->handle($project, $user);

    expect($project->members()->whereKey($user->id)->exists())->toBeTrue()
        ->and($project->roleNamesFor($user))->toBe(['member']);
});

it('removes a member and all of their roles', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMember($user, ['member', 'admin'])->create();

    app(RemoveProjectMember::class)->handle($project, $user);

    expect($project->members()->whereKey($user->id)->exists())->toBeFalse()
        ->and($project->roleNamesFor($user))->toBe([]);
});
