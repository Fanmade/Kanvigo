<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links sidebar projects straight to the board', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('project.board', $project), false);
});

it('keeps the project detail page reachable from the board header', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $this->actingAs($user)
        ->get(route('project.board', $project))
        ->assertOk()
        ->assertSee(route('project.show', $project), false);
});

it('shows the toolbar new-task shortcut to a user who has a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('toolbar-new-task', false)
        ->assertSee('open-create-task', false);
});

it('hides the toolbar new-task shortcut from a user with no project', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('toolbar-new-task', false);
});
