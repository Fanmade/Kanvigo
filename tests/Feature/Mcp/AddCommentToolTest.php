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

it('posts a reply carrying the parent comment id', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();
    $parent = $task->comments()->create(['user_id' => $user->id, 'body' => 'Top level']);

    KanvigoServer::tool(AddCommentTool::class, [
        'reference' => $task->reference,
        'body' => 'A reply',
        'reply_to' => $parent->id,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('parent_id', $parent->id)->etc());

    assertDatabaseHas('comments', ['body' => 'A reply', 'parent_id' => $parent->id]);
});

it('flattens a reply to a reply onto the root comment', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();
    $root = $task->comments()->create(['user_id' => $user->id, 'body' => 'Root']);
    $reply = $task->comments()->create(['user_id' => $user->id, 'body' => 'Reply', 'parent_id' => $root->id]);

    KanvigoServer::tool(AddCommentTool::class, [
        'reference' => $task->reference,
        'body' => 'Reply to the reply',
        'reply_to' => $reply->id,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('parent_id', $root->id)->etc());
});

it('errors replying to a comment that is not on the target item', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();
    $other = Task::factory()->for($project)->create();
    $foreign = $other->comments()->create(['user_id' => $user->id, 'body' => 'Elsewhere']);

    KanvigoServer::tool(AddCommentTool::class, [
        'reference' => $task->reference,
        'body' => 'Misdirected reply',
        'reply_to' => $foreign->id,
    ])->assertHasErrors();
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
