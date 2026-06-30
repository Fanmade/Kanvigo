<?php

use App\Enums\Status;
use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetTaskTool;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes the task type name (or null when untyped)', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $type = TaskType::factory()->for($project)->create(['name' => 'Bug']);
    $task = Task::factory()->for($project)->create(['task_type_id' => $type->id]);

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('type', 'Bug')->etc());
});

it('returns a task in a project the user is a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertSee($task->reference)
        ->assertSee($task->status->value);
});

it('denies access to a task in a project the user is not a member of', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertHasErrors();
});

it('returns an error for a malformed task reference', function () {
    $user = User::factory()->create();

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => 'ABC1'])
        ->assertHasErrors();
});

it('includes the task tags', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();
    $task->syncTags('design');

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertSee('design');
});

it('exposes the task hierarchy: parent, ancestors, children and rolled-up progress', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $root = Task::factory()->for($project)->create();
    $middle = Task::factory()->for($project)->childOf($root)->create();
    $leaf = Task::factory()->for($project)->childOf($middle)->status(Status::Done)->create();

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $middle->reference])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($root, $middle, $leaf) {
            $json->where('reference', $middle->reference)
                ->where('parent', $root->reference)
                ->where('ancestors', [$root->reference])
                ->where('children.0.reference', $leaf->reference)
                ->where('children.0.status', Status::Done->value)
                ->where('progress.done', 1)   // the one Done descendant
                ->where('progress.total', 1)  // the leaf is the only descendant
                ->etc();
        });
});

it('reports a top-level task as having no parent or ancestors', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(function ($json) {
            $json->where('parent', null)
                ->where('ancestors', [])
                ->where('children', [])
                ->etc();
        });
});

it('exposes assignee names but not their email addresses', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    $assignee = User::factory()->create(['name' => 'Dana', 'email' => 'dana@example.com']);
    $task->assignees()->attach($assignee);

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($assignee) {
            $json->where('assignees.0.id', $assignee->public_id)
                ->where('assignees.0.name', 'Dana')
                ->missing('assignees.0.email')
                ->etc();
        });
});

it('exposes the task comments oldest first, with author, timestamp and reply threading', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    $root = $task->comments()->create(['user_id' => $user->id, 'body' => 'First thought']);
    $reply = $task->comments()->create(['user_id' => $user->id, 'body' => 'A reply', 'parent_id' => $root->id]);

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($root, $reply) {
            $json->where('comments.0.id', $root->id)
                ->where('comments.0.parent_id', null)
                ->where('comments.0.author', 'Ada Lovelace')
                ->where('comments.0.body', 'First thought')
                ->where('comments.0.is_deleted', false)
                ->where('comments.0.created_at', fn ($value) => is_string($value) && $value !== '')
                ->where('comments.1.id', $reply->id)
                ->where('comments.1.parent_id', $root->id)
                ->where('comments.1.body', 'A reply')
                ->etc();
        });
});

it('keeps a deleted comment as an empty-bodied tombstone in the payload', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    $root = $task->comments()->create(['user_id' => $user->id, 'body' => 'Will be removed']);
    $root->forceFill(['is_deleted' => true, 'body' => '', 'delete_reason' => 'off-topic'])->save();

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(function ($json) {
            $json->where('comments.0.is_deleted', true)
                ->where('comments.0.body', '')
                ->etc();
        });
});

it('returns an empty comments array when the task has none', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('comments', [])->etc());
});

it('lists the task attachments with their ids', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withMembers([$user])->create(['short_name' => 'ABC']);
    $task = Task::factory()->for($project)->create();
    $attachment = Attachment::factory()->create([
        'attachable_id' => $task->id,
        'attachable_type' => $task->getMorphClass(),
        'name' => 'diagram.png',
        'is_inline' => true,
    ]);

    KanvigoServer::actingAs($user)->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertOk()
        ->assertStructuredContent(function ($json) use ($attachment) {
            $json->where('attachments.0.id', $attachment->id)
                ->where('attachments.0.name', 'diagram.png')
                ->where('attachments.0.is_inline', true)
                ->etc();
        });
});
