<?php

use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\AddCommentTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

it('adds a comment to a task', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::tool(AddCommentTool::class, [
        'reference' => $task->reference,
        'body' => 'Looks good to me',
    ])
        ->assertOk()
        ->assertSee('Looks good to me');

    assertDatabaseHas('comments', [
        'commentable_type' => $task->getMorphClass(),
        'commentable_id' => $task->id,
        'user_id' => $user->id,
        'body' => 'Looks good to me',
    ]);
});

it('adds a comment to a project', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanvigoServer::tool(AddCommentTool::class, [
        'reference' => $project->short_name,
        'body' => 'A project comment',
    ])->assertOk();

    assertDatabaseHas('comments', [
        'commentable_type' => $project->getMorphClass(),
        'commentable_id' => $project->id,
        'body' => 'A project comment',
    ]);
});

it('denies commenting on an item the user cannot access', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::tool(AddCommentTool::class, [
        'reference' => $task->reference,
        'body' => 'Should fail',
    ])->assertHasErrors();
});

it('denies commenting with a read-only token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::tool(AddCommentTool::class, [
        'reference' => $task->reference,
        'body' => 'Should fail',
    ])->assertHasErrors();
});
