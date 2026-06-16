<?php

use App\Enums\Status;
use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

it('creates a task in a story the user can access', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(CreateTaskTool::class, [
        'reference' => $story->reference,
        'title' => 'A task',
        'status' => Status::ToDo->value,
    ])
        ->assertOk()
        ->assertSee($story->reference.'-')
        ->assertSee(Status::ToDo->value);

    assertDatabaseHas('tasks', ['story_id' => $story->id, 'title' => 'A task', 'status' => Status::ToDo->value]);
});

it('defaults a new task to Planned status', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(CreateTaskTool::class, [
        'reference' => $story->reference,
        'title' => 'A task',
    ])->assertOk();

    assertDatabaseHas('tasks', ['story_id' => $story->id, 'status' => Status::Planned->value]);
});

it('denies creating a task in a story the user cannot access', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(CreateTaskTool::class, [
        'reference' => $story->reference,
        'title' => 'A task',
    ])->assertHasErrors();
});

it('denies creating a task with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(CreateTaskTool::class, [
        'reference' => $story->reference,
        'title' => 'A task',
    ])->assertHasErrors();
});
