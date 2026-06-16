<?php

use App\Enums\Status;
use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

it('updates a task title and description', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create(['title' => 'Old']);

    KanbrioServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'title' => 'New title',
    ])
        ->assertOk()
        ->assertSee('New title');

    assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'New title']);
});

it('changes the task status and records the activity', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->status(Status::Planned)->create();

    KanbrioServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'status' => Status::Done->value,
    ])
        ->assertOk()
        ->assertSee(Status::Done->value);

    assertDatabaseHas('tasks', ['id' => $task->id, 'status' => Status::Done->value]);
    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'status_changed',
        'field' => 'status',
        'new_value' => Status::Done->value,
    ]);
});

it('errors when no fields are provided to update', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    KanbrioServer::tool(UpdateTaskTool::class, ['reference' => $task->reference])
        ->assertHasErrors();
});

it('denies updating a task with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    KanbrioServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'status' => Status::Done->value,
    ])->assertHasErrors();
});
