<?php

use App\Enums\Priority;
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
        ->assertSee($project->short_name.'-')
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

it('creates a task with an explicit priority', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->priority(Priority::Low)->create();

    KanbrioServer::tool(CreateTaskTool::class, [
        'reference' => $story->reference,
        'title' => 'A task',
        'priority' => 'High',
    ])
        ->assertOk()
        ->assertSee('High');

    assertDatabaseHas('tasks', ['story_id' => $story->id, 'priority' => Priority::High->value]);
});

it('inherits the story priority when none is given via MCP', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->priority(Priority::Highest)->create();

    KanbrioServer::tool(CreateTaskTool::class, [
        'reference' => $story->reference,
        'title' => 'A task',
    ])->assertOk();

    assertDatabaseHas('tasks', ['story_id' => $story->id, 'priority' => Priority::Highest->value]);
});

it('rejects an unknown priority', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(CreateTaskTool::class, [
        'reference' => $story->reference,
        'title' => 'A task',
        'priority' => 'Urgent',
    ])->assertHasErrors();
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

it('applies tags when creating a task via MCP', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $story = Story::factory()->for($project)->create();

    KanbrioServer::tool(CreateTaskTool::class, [
        'reference' => $story->reference,
        'title' => 'A task',
        'tags' => ['design', 'bug'],
    ])
        ->assertOk()
        ->assertSee('design');

    assertDatabaseHas('tags', ['name' => 'design']);
    assertDatabaseHas('tags', ['name' => 'bug']);
});
