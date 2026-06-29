<?php

use App\Actions\CreateProject;
use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provisions the project, its owner and default task types', function () {
    $owner = User::factory()->create();

    $project = app(CreateProject::class)->handle($owner, 'My Project', 'MP', 'A description.');

    expect($project->members()->whereKey($owner->id)->exists())->toBeTrue()
        ->and($project->roleNamesFor($owner))->toBe(['owner'])
        ->and($project->taskTypes()->count())->toBeGreaterThan(0);
});

it('rolls the whole provisioning back when a step fails mid-way', function () {
    $owner = User::factory()->create();

    // Fail after the project row and membership are written, so the rollback has
    // something to undo.
    $this->mock(ProjectRoleProvisioner::class)
        ->shouldReceive('syncMember')
        ->andThrow(new RuntimeException('boom'));

    expect(fn () => app(CreateProject::class)->handle($owner, 'Doomed', 'DM'))
        ->toThrow(RuntimeException::class);

    expect(Project::count())->toBe(0)
        ->and($owner->projects()->count())->toBe(0);
});
