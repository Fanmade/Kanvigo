<?php

use App\Actions\ChangeTaskStatus;
use App\Enums\CascadePreference;
use App\Enums\Priority;
use App\Enums\Status;
use App\Mcp\Servers\KanvigoServer;
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

    KanvigoServer::tool(UpdateTaskTool::class, [
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

    KanvigoServer::tool(UpdateTaskTool::class, [
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

it('closes the parent over MCP when the last subtask is completed under the "always" preference', function () {
    $user = User::factory()->create();
    $user->setPreference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY, CascadePreference::Always->value);
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $parent = Task::factory()->for($project)->status(Status::InProgress)->create();
    $child = Task::factory()->for($project)->childOf($parent)->status(Status::ToDo)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $child->reference,
        'status' => Status::Done->value,
    ])->assertOk();

    assertDatabaseHas('tasks', ['id' => $parent->id, 'status' => Status::Done->value]);
});

it('leaves the parent untouched over MCP under the default "ask" preference', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $parent = Task::factory()->for($project)->status(Status::InProgress)->create();
    $child = Task::factory()->for($project)->childOf($parent)->status(Status::ToDo)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $child->reference,
        'status' => Status::Done->value,
    ])->assertOk();

    // MCP cannot prompt, so the parent is left open under "ask".
    assertDatabaseHas('tasks', ['id' => $parent->id, 'status' => Status::InProgress->value]);
});

it('updates a task priority', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->priority(Priority::Medium)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
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

    KanvigoServer::tool(UpdateTaskTool::class, ['reference' => $task->reference])
        ->assertHasErrors();
});

it('denies updating a task with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
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

    KanvigoServer::tool(UpdateTaskTool::class, [
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
