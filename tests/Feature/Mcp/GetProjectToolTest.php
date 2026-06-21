<?php

use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\GetProjectTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a project the user is a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertSee('ABC')
        ->assertSee($task->reference);
});

it('denies access to a project the user is not a member of', function () {
    $user = User::factory()->create();
    Project::factory()->create(['short_name' => 'ABC']);

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertHasErrors();
});

it('returns an error for a project that does not exist', function () {
    $user = User::factory()->create();

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class, ['short_name' => 'NOPE'])
        ->assertHasErrors();
});

it('requires the short_name argument', function () {
    $user = User::factory()->create();

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class)
        ->assertHasErrors();
});

it('exposes the project comments with author and body', function () {
    $user = User::factory()->create(['name' => 'Grace Hopper']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    $comment = $project->comments()->create(['user_id' => $user->id, 'body' => 'Kickoff note']);

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($comment) {
            $json->where('comments.0.id', $comment->id)
                ->where('comments.0.author', 'Grace Hopper')
                ->where('comments.0.body', 'Kickoff note')
                ->etc();
        });
});

it('returns an empty comments array when the project has none', function () {
    $user = User::factory()->create();
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);

    KanbrioServer::actingAs($user)->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('comments', [])->etc());
});
