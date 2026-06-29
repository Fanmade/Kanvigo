<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Livewire\Activity\ActivityFeed;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Fanmade\DelegatedPermissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('gates the activity feed behind view-activity-log', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();

    $member = userWithRole($project, 'member');                 // holds view-activity-log
    $restricted = userWithPermissions($project, []);            // only view-project

    Livewire::actingAs($member)
        ->test(ActivityFeed::class, ['subject' => $task])
        ->assertOk();

    Livewire::actingAs($restricted)
        ->test(ActivityFeed::class, ['subject' => $task])
        ->assertForbidden();
});

it('stops serving activity when view-activity-log is revoked mid-session', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();

    $owner = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $roles = app(RoleManager::class);
    $full = $roles->createRole('Full', $owner, ['view-project', 'view-activity-log'], $project);
    $viewOnly = $roles->createRole('View only', $owner, ['view-project'], $project);

    $user = User::factory()->create()->assignRole($full);

    $component = Livewire::actingAs($user)
        ->test(ActivityFeed::class, ['subject' => $task])
        ->assertOk();

    // Lose activity-log access but keep view-project (a "ghost" reader).
    $user->removeRole($full)->assignRole($viewOnly);

    $component->call('showMore')->assertForbidden();
});
