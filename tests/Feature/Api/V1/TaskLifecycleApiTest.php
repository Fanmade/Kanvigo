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

it('cancels a task with a reason', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$task->reference}/cancel", [
        'cancel_reason' => 'WontFix',
        'cancel_message' => 'Out of scope',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'Canceled')
        ->assertJsonPath('data.cancel_reason', 'WontFix')
        ->assertJsonPath('data.cancel_message', 'Out of scope');

    expect($task->fresh()->isCanceled())->toBeTrue();
});

it('rejects canceling an already canceled task', function () {
    $task = Task::factory()->for($this->project)->canceled()->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$task->reference}/cancel", ['cancel_reason' => 'Duplicate'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cancel_reason');
});

it('forbids canceling with a read-only token', function () {
    $task = Task::factory()->for($this->project)->create();
    Sanctum::actingAs($this->user, ['read']);

    $this->postJson("/api/v1/tasks/{$task->reference}/cancel", ['cancel_reason' => 'WontFix'])
        ->assertForbidden();
});

it('reopens a canceled task', function () {
    $task = Task::factory()->for($this->project)->canceled()->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$task->reference}/reopen")
        ->assertOk()
        ->assertJsonPath('data.status', Status::Planned->value);

    expect($task->fresh()->isCanceled())->toBeFalse();
});

it('rejects reopening a task that is not canceled', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson("/api/v1/tasks/{$task->reference}/reopen")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('sets a task assignees to project members', function () {
    $task = Task::factory()->for($this->project)->create();
    $member = User::factory()->create(['name' => 'Dana']);
    joinProject($this->project, $member);
    $outsider = User::factory()->create();

    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->putJson("/api/v1/tasks/{$task->reference}/assignees", [
        'assignee_ids' => [$member->public_id, $outsider->public_id],
    ])
        ->assertOk()
        ->assertJsonPath('data.assignees.0.name', 'Dana')
        ->assertJsonPath('data.assignees.0.id', $member->public_id) // the handle round-trips
        ->assertJsonCount(1, 'data.assignees'); // the non-member is ignored

    expect($task->fresh()->assignees()->pluck('users.id')->all())->toBe([$member->id]);
});

it('clears a task assignees with an empty set', function () {
    $task = Task::factory()->for($this->project)->create();
    $task->assignees()->attach($this->user);

    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->putJson("/api/v1/tasks/{$task->reference}/assignees", ['assignee_ids' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.assignees');

    expect($task->fresh()->assignees()->count())->toBe(0);
});
