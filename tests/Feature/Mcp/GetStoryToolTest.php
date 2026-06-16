<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\GetStoryTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a story in a project the user is a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    KanbrioServer::actingAs($user)->tool(GetStoryTool::class, ['reference' => $story->reference])
        ->assertOk()
        ->assertSee($story->reference)
        ->assertSee($task->reference);
});

it('denies access to a story in a project the user is not a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::actingAs($user)->tool(GetStoryTool::class, ['reference' => $story->reference])
        ->assertHasErrors();
});

it('returns an error for a malformed story reference', function () {
    $user = User::factory()->create();

    KanbrioServer::actingAs($user)->tool(GetStoryTool::class, ['reference' => 'not-a-ref'])
        ->assertHasErrors();
});
