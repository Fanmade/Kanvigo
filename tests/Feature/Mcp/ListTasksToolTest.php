<?php

use App\Enums\Status;
use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists the tasks of a story the user can access', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    KanbrioServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $story->reference])
        ->assertOk()
        ->assertSee($task->reference);
});

it('filters tasks by status', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $done = Task::factory()->for($story)->status(Status::Done)->create();
    $planned = Task::factory()->for($story)->status(Status::Planned)->create();

    KanbrioServer::actingAs($user)->tool(ListTasksTool::class, [
        'reference' => $story->reference,
        'status' => Status::Done->value,
    ])
        ->assertOk()
        ->assertSee($done->reference)
        ->assertDontSee($planned->reference);
});

it('denies listing tasks of a story the user cannot access', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    Task::factory()->for($story)->create();

    KanbrioServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $story->reference])
        ->assertHasErrors();
});
