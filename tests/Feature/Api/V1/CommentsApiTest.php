<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
});

it('adds a comment to a task with a write token', function () {
    $task = Task::factory()->for($this->project)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$task->reference}/comments", ['body' => '<p>Looks good</p>'])
        ->assertCreated()
        ->assertJsonPath('data.body', '<p>Looks good</p>')
        ->assertJsonPath('data.author', $this->user->name);

    assertDatabaseHas('comments', [
        'commentable_id' => $task->id,
        'commentable_type' => $task->getMorphClass(),
        'user_id' => $this->user->id,
    ]);
    assertDatabaseHas('activities', ['subject_id' => $task->id, 'action' => 'commented']);
});

it('adds a comment to a project', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson('/api/v1/projects/ABC/comments', ['body' => '<p>Kickoff</p>'])
        ->assertCreated()
        ->assertJsonPath('data.body', '<p>Kickoff</p>');

    assertDatabaseHas('comments', [
        'commentable_id' => $this->project->id,
        'commentable_type' => $this->project->getMorphClass(),
    ]);
});

it('forbids commenting with a read-only token', function () {
    $task = Task::factory()->for($this->project)->create();
    Sanctum::actingAs($this->user, ['read']);

    $this->postJson("/api/v1/tasks/{$task->reference}/comments", ['body' => 'Nope'])
        ->assertForbidden();
});

it('requires a comment body', function () {
    $task = Task::factory()->for($this->project)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$task->reference}/comments", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

it('404s commenting on a task the user cannot access', function () {
    $other = Project::factory()->create(['short_name' => 'XYZ']);
    $task = Task::factory()->for($other)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$task->reference}/comments", ['body' => 'Hi'])
        ->assertNotFound();
});

it('403s commenting for a viewer who can see the task but lacks create-comment', function () {
    $viewer = User::factory()->create();
    joinProject($this->project, $viewer, 'viewer');
    $task = Task::factory()->for($this->project)->create();
    Sanctum::actingAs($viewer, ['read', 'write']);

    // The viewer can see the task, so a denied create is a 403, not a 404.
    $this->postJson("/api/v1/tasks/{$task->reference}/comments", ['body' => 'Nope'])
        ->assertForbidden();
});
