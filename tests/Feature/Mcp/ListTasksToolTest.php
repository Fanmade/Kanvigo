<?php

use App\Enums\Status;
use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists the tasks of a project the user can access', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanbrioServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $project->short_name])
        ->assertOk()
        ->assertSee($task->reference);
});

it('filters tasks by status', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $done = Task::factory()->for($project)->status(Status::Done)->create();
    $planned = Task::factory()->for($project)->status(Status::Planned)->create();

    KanbrioServer::actingAs($user)->tool(ListTasksTool::class, [
        'reference' => $project->short_name,
        'status' => Status::Done->value,
    ])
        ->assertOk()
        ->assertSee($done->reference)
        ->assertDontSee($planned->reference);
});

it('denies listing tasks of a project the user cannot access', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    Task::factory()->for($project)->create();

    KanbrioServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $project->short_name])
        ->assertHasErrors();
});

it('includes each task\'s tags', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();
    $task->syncTags('design');

    KanbrioServer::actingAs($user)->tool(ListTasksTool::class, ['reference' => $project->short_name])
        ->assertOk()
        ->assertSee('design');
});
