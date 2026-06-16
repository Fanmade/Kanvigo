<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\GetTaskTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a task in a project the user is a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    KanbrioServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertSee($task->reference)
        ->assertSee($task->status->value);
});

it('denies access to a task in a project the user is not a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    KanbrioServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertHasErrors();
});

it('returns an error for a malformed task reference', function () {
    $user = User::factory()->create();

    KanbrioServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => 'ABC1'])
        ->assertHasErrors();
});
