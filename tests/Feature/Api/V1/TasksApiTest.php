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

it('requires authentication for the task list', function () {
    $this->getJson('/api/v1/projects/ABC/tasks')->assertUnauthorized();
});

it('lists a project tasks with the expected shape', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Wire it']);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson('/api/v1/projects/ABC/tasks')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $task->reference)
        ->assertJsonPath('data.0.title', 'Wire it')
        ->assertJsonPath('data.0.status', 'ToDo')
        ->assertJsonStructure(['data' => [['reference', 'parent', 'title', 'priority', 'status', 'due_date', 'cancel_reason', 'tags', 'is_blocked']], 'links', 'meta']);
});

it('filters the task list by status', function () {
    Task::factory()->for($this->project)->status(Status::Planned)->create(['title' => 'Planned one']);
    Task::factory()->for($this->project)->status(Status::Done)->create(['title' => 'Done one']);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson('/api/v1/projects/ABC/tasks?status=Done')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Done one');
});

it('filters the task list to a parent\'s direct subtasks', function () {
    $parent = Task::factory()->for($this->project)->create(['title' => 'Parent']);
    $child = Task::factory()->for($this->project)->childOf($parent)->create(['title' => 'Child']);
    Task::factory()->for($this->project)->create(['title' => 'Unrelated']);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/projects/ABC/tasks?parent={$parent->reference}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $child->reference);
});

it('rejects an invalid status filter', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson('/api/v1/projects/ABC/tasks?status=Nope')
        ->assertStatus(422);
});

it('404s the task list for a project the user cannot access', function () {
    Project::factory()->create(['short_name' => 'XYZ']);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson('/api/v1/projects/XYZ/tasks')->assertNotFound();
});

it('shows a single task by reference', function () {
    $task = Task::factory()->for($this->project)->create(['title' => 'Detail me']);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/tasks/{$task->reference}")
        ->assertOk()
        ->assertJsonPath('data.reference', $task->reference)
        ->assertJsonPath('data.title', 'Detail me');
});

it('404s a task in a project the user cannot access', function () {
    $other = Project::factory()->create(['short_name' => 'XYZ']);
    $task = Task::factory()->for($other)->create();

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/tasks/{$task->reference}")->assertNotFound();
});
