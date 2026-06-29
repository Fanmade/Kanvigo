<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
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

it('forbids creating a task with a read-only token', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->postJson('/api/v1/projects/ABC/tasks', ['title' => 'Nope'])
        ->assertForbidden();

    expect($this->project->tasks()->count())->toBe(0);
});

it('creates a task with a write token', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson('/api/v1/projects/ABC/tasks', [
        'title' => 'Build the thing',
        'priority' => 'High',
        'status' => 'ToDo',
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Build the thing')
        ->assertJsonPath('data.priority', 'High')
        ->assertJsonPath('data.status', 'ToDo');

    expect($this->project->tasks()->where('title', 'Build the thing')->exists())->toBeTrue();
});

it('creates a task with a type and tags', function () {
    $type = TaskType::factory()->for($this->project)->create(['name' => 'Bug']);
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson('/api/v1/projects/ABC/tasks', [
        'title' => 'A defect',
        'type' => 'bug',
        'tags' => ['urgent', 'backend'],
    ])->assertCreated();

    $task = $this->project->tasks()->where('title', 'A defect')->first();

    expect($task->task_type_id)->toBe($type->id)
        ->and($task->tags->pluck('name')->all())->toEqualCanonicalizing(['urgent', 'backend']);
});

it('404s when creating a task in a project the user cannot access', function () {
    Project::factory()->create(['short_name' => 'XYZ']);
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson('/api/v1/projects/XYZ/tasks', ['title' => 'Sneaky'])
        ->assertNotFound();
});

it('rejects creating a task already in the Canceled status', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->postJson('/api/v1/projects/ABC/tasks', [
        'title' => 'Born canceled',
        'status' => 'Canceled',
    ])->assertJsonValidationErrors('status');

    expect($this->project->tasks()->count())->toBe(0);
});

it('updates a task and routes status through the cascade action', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->patchJson("/api/v1/tasks/{$task->reference}", [
        'title' => 'Renamed',
        'priority' => 'Highest',
        'status' => 'Done',
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.status', 'Done');

    expect($task->fresh()->status)->toBe(Status::Done);
    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'status_changed',
        'new_value' => Status::Done->value,
    ]);
});

it('forbids updating a task with a read-only token', function () {
    $task = Task::factory()->for($this->project)->create(['title' => 'Original']);
    Sanctum::actingAs($this->user, ['read']);

    $this->patchJson("/api/v1/tasks/{$task->reference}", ['title' => 'Changed'])
        ->assertForbidden();

    expect($task->fresh()->title)->toBe('Original');
});

it('rejects an unknown type name when updating', function () {
    $task = Task::factory()->for($this->project)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->patchJson("/api/v1/tasks/{$task->reference}", ['type' => 'Nonexistent'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('rejects a status change on a canceled task', function () {
    $task = Task::factory()->for($this->project)->canceled()->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->patchJson("/api/v1/tasks/{$task->reference}", ['status' => 'Done'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('404s when updating a task the user cannot access', function () {
    $other = Project::factory()->create(['short_name' => 'XYZ']);
    $task = Task::factory()->for($other)->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->patchJson("/api/v1/tasks/{$task->reference}", ['title' => 'Hack'])
        ->assertNotFound();
});
