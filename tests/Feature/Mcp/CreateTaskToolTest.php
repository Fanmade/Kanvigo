<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

it('creates a task in a project the user can access', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
        'status' => Status::ToDo->value,
    ])
        ->assertOk()
        ->assertSee($project->short_name.'-')
        ->assertSee(Status::ToDo->value);

    assertDatabaseHas('tasks', ['project_id' => $project->id, 'title' => 'A task', 'status' => Status::ToDo->value]);
});

it('sanitizes an HTML description written through the tool', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
        'description' => '<p>Plan</p><script>alert(1)</script>',
    ])->assertOk();

    $task = $project->tasks()->where('title', 'A task')->first();

    expect($task->description)
        ->toContain('<p>Plan</p>')
        ->not->toContain('<script');
});

it('defaults a new task to Planned status', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
    ])->assertOk();

    assertDatabaseHas('tasks', ['project_id' => $project->id, 'status' => Status::Planned->value]);
});

it('creates a task with an explicit priority', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
        'priority' => 'High',
    ])
        ->assertOk()
        ->assertSee('High');

    assertDatabaseHas('tasks', ['project_id' => $project->id, 'priority' => Priority::High->value]);
});

it('defaults a new task to the default priority when none is given via MCP', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
    ])->assertOk();

    assertDatabaseHas('tasks', ['project_id' => $project->id, 'priority' => Priority::default()->value]);
});

it('rejects an unknown priority', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
        'priority' => 'Urgent',
    ])->assertHasErrors();
});

it('denies creating a task in a project the user cannot access', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
    ])->assertHasErrors();
});

it('denies creating a task with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
    ])->assertHasErrors();
});

it('nests a task under a parent when a parent reference is given', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $parent = Task::factory()->for($project)->create();

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'parent' => $parent->reference,
        'title' => 'A subtask',
    ])
        ->assertOk()
        ->assertSee($parent->reference); // the output echoes the parent reference

    assertDatabaseHas('tasks', ['title' => 'A subtask', 'parent_id' => $parent->id, 'project_id' => $project->id]);
});

it('rejects a parent task in a different project', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $otherParent = Task::factory()->for(Project::factory()->withMembers([$user])->create(['short_name' => 'XYZ']))->create();

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'parent' => $otherParent->reference,
        'title' => 'Mismatched',
    ])->assertHasErrors();

    assertDatabaseMissing('tasks', ['title' => 'Mismatched']);
});

it('refuses to nest a task beyond the maximum depth', function () {
    config(['kanvigo.tasks.max_depth' => 2]);
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $root = Task::factory()->for($project)->create();
    $child = Task::factory()->for($project)->childOf($root)->create(); // depth 2 = max

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'parent' => $child->reference,
        'title' => 'Too deep',
    ])->assertHasErrors();

    assertDatabaseMissing('tasks', ['title' => 'Too deep']);
});

it('applies tags when creating a task via MCP', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(CreateTaskTool::class, [
        'reference' => $project->short_name,
        'title' => 'A task',
        'tags' => ['design', 'bug'],
    ])
        ->assertOk()
        ->assertSee('design');

    assertDatabaseHas('tags', ['name' => 'design']);
    assertDatabaseHas('tags', ['name' => 'bug']);
});
