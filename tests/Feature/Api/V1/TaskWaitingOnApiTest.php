<?php

use App\Actions\SetWaitingOn;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->user = userWithRole($this->project, 'member');
    $this->awaited = userWithRole($this->project, 'member');
    $this->task = Task::factory()->for($this->project)->create();
});

it('sets the waiting-on member', function () {
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->patchJson('/api/v1/tasks/'.$this->task->reference, ['waiting_on' => $this->awaited->public_id])
        ->assertOk()
        ->assertJsonPath('data.waiting_on.id', $this->awaited->public_id)
        ->assertJsonPath('data.waiting_on.name', $this->awaited->name);

    expect($this->task->fresh()->waiting_on_user_id)->toBe($this->awaited->id);
});

it('clears the waiting-on member with null', function () {
    app(SetWaitingOn::class)->handle($this->task, $this->awaited);
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->patchJson('/api/v1/tasks/'.$this->task->reference, ['waiting_on' => null])
        ->assertOk()
        ->assertJsonPath('data.waiting_on', null);

    expect($this->task->fresh()->waiting_on_user_id)->toBeNull();
});

it('rejects a user who is not a member of the project', function () {
    $outsider = User::factory()->create();
    Sanctum::actingAs($this->user, ['read', 'write']);

    $this->patchJson('/api/v1/tasks/'.$this->task->reference, ['waiting_on' => $outsider->public_id])
        ->assertJsonValidationErrors('waiting_on');

    expect($this->task->fresh()->waiting_on_user_id)->toBeNull();
});

it('reports the waiting-on member when reading a task', function () {
    app(SetWaitingOn::class)->handle($this->task, $this->awaited);
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson('/api/v1/tasks/'.$this->task->reference)
        ->assertOk()
        ->assertJsonPath('data.waiting_on.name', $this->awaited->name);

    $this->getJson('/api/v1/projects/ABC/tasks')
        ->assertOk()
        ->assertJsonPath('data.0.waiting_on.name', $this->awaited->name);
});
