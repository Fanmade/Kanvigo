<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
});

it('returns full task detail on show', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Parent']);
    $blocker = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $task->addBlocker($blocker);
    $child = Task::factory()->for($this->project)->childOf($task)->create(['title' => 'Child']);

    $assignee = User::factory()->create(['name' => 'Dana']);
    joinProject($this->project, $assignee);
    $task->assignees()->attach($assignee);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/tasks/{$task->reference}")
        ->assertOk()
        ->assertJsonPath('data.reference', $task->reference)
        ->assertJsonPath('data.blocked_by', [$blocker->reference])
        ->assertJsonPath('data.is_blocked', true)
        ->assertJsonPath('data.assignees.0.name', 'Dana')
        ->assertJsonPath('data.children.0.reference', $child->reference)
        ->assertJsonPath('data.children.0.title', 'Child')
        ->assertJsonPath('data.progress.total', 1)
        ->assertJsonStructure(['data' => [
            'cancel_message',
            'progress' => ['done', 'total'],
            'assignees' => [['id', 'name']],
            'blocked_by', 'blocks', 'children', 'attachments',
        ]]);
});

it('returns the task description as stored', function () {
    $task = Task::factory()->for($this->project)->create([
        'description' => '<p>Write the <strong>opening</strong> scene.</p>',
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/tasks/{$task->reference}")
        ->assertOk()
        ->assertJsonPath('data.description', '<p>Write the <strong>opening</strong> scene.</p>');
});

it('returns a null description for a task without one', function () {
    $task = Task::factory()->for($this->project)->create(['description' => null]);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/tasks/{$task->reference}")
        ->assertOk()
        ->assertJsonPath('data.description', null);
});

it('round-trips a description written through the API', function () {
    $task = Task::factory()->for($this->project)->create();

    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->patchJson("/api/v1/tasks/{$task->reference}", ['description' => '<p>Rewritten.</p>'])
        ->assertOk();

    // The API accepted a description long before it returned one; a client can
    // now read back what it wrote.
    $this->getJson("/api/v1/tasks/{$task->reference}")
        ->assertOk()
        ->assertJsonPath('data.description', '<p>Rewritten.</p>');
});

it('keeps the description out of the task list, which stays lean', function () {
    Task::factory()->for($this->project)->create(['description' => '<p>Long prose.</p>']);

    Sanctum::actingAs($this->user, ['read']);

    $task = $this->getJson('/api/v1/projects/ABC/tasks')->assertOk()->json('data.0');

    expect($task)->not->toHaveKey('description');
});

it('does not expose assignee email addresses in task detail', function () {
    $task = Task::factory()->for($this->project)->create();
    $assignee = User::factory()->create(['email' => 'dana@example.com']);
    joinProject($this->project, $assignee);
    $task->assignees()->attach($assignee);

    Sanctum::actingAs($this->user, ['read']);

    $assignee = $this->getJson("/api/v1/tasks/{$task->reference}")
        ->assertOk()
        ->json('data.assignees.0');

    expect($assignee)->toHaveKeys(['id', 'name'])
        ->and($assignee)->not->toHaveKey('email');
});

it('does not include detail fields in the task list', function () {
    Task::factory()->for($this->project)->create();
    Sanctum::actingAs($this->user, ['read']);

    $first = $this->getJson('/api/v1/projects/ABC/tasks')->assertOk()->json('data.0');

    expect($first)->not->toHaveKey('assignees')
        ->and($first)->not->toHaveKey('attachments')
        ->and($first)->toHaveKey('reference');
});

it('returns the comment count on project show', function () {
    $this->project->comments()->create(['user_id' => $this->user->id, 'body' => '<p>Kickoff</p>']);
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson('/api/v1/projects/ABC')
        ->assertOk()
        ->assertJsonPath('data.comment_count', 1);
});
