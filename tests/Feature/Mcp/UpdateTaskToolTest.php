<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
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
    $task = Task::factory()->for($project)->create(['title' => 'Old']);

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
    $task = Task::factory()->for($project)->status(Status::Planned)->create();

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

it('updates a task priority', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->priority(Priority::Medium)->create();

    KanbrioServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'priority' => 'Lowest',
    ])
        ->assertOk()
        ->assertSee('Lowest');

    assertDatabaseHas('tasks', ['id' => $task->id, 'priority' => Priority::Lowest->value]);
});

it('errors when no fields are provided to update', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanbrioServer::tool(UpdateTaskTool::class, ['reference' => $task->reference])
        ->assertHasErrors();
});

it('denies updating a task with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanbrioServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'status' => Status::Done->value,
    ])->assertHasErrors();
});

it('replaces a task\'s tags and records the activity via MCP', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();
    $task->syncTags('old');

    KanbrioServer::tool(UpdateTaskTool::class, [
        'reference' => $task->reference,
        'tags' => ['design'],
    ])
        ->assertOk()
        ->assertSee('design');

    expect($task->fresh()->tags()->pluck('name')->all())->toBe(['design']);
    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'tags_changed',
    ]);

    $activity = $task->activities()->where('action', 'tags_changed')->first();
    expect(json_decode((string) $activity->new_value, true))->toBe(['design'])
        ->and(json_decode((string) $activity->old_value, true))->toBe(['old']);
});
