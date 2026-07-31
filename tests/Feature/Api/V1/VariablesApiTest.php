<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->member = userWithRole($this->project, 'member');
});

it('lists a project variables in name order', function () {
    Sanctum::actingAs($this->member, ['read']);

    Variable::factory()->for($this->project)->create([
        'name' => 'villain',
        'value' => 'The Sheriff',
        'description' => 'The antagonist',
    ]);
    Variable::factory()->for($this->project)->unset()->create(['name' => 'hero']);

    $this->getJson('/api/v1/projects/ABC/variables')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', ['name' => 'hero', 'value' => null, 'description' => null])
        ->assertJsonPath('data.1', ['name' => 'villain', 'value' => 'The Sheriff', 'description' => 'The antagonist']);
});

it('404s the variables of a project the caller is not a member of', function () {
    Sanctum::actingAs(User::factory()->create(), ['read']);

    $this->getJson('/api/v1/projects/ABC/variables')->assertNotFound();
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/projects/ABC/variables')->assertUnauthorized();
});

it('reports the variables a task description uses', function () {
    Sanctum::actingAs($this->member, ['read']);

    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    Variable::factory()->for($this->project)->create(['name' => 'unused', 'value' => 'Nowhere']);
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero] arrives.</p>']);

    $this->getJson('/api/v1/tasks/'.$task->reference)
        ->assertOk()
        // Only what the content names — the project's other variables stay out.
        ->assertJsonCount(1, 'data.variables')
        ->assertJsonPath('data.variables.0', ['name' => 'hero', 'value' => 'Robin Hood']);
});

it('returns a doc body as stored, with the variables alongside it', function () {
    Sanctum::actingAs($this->member, ['read']);

    Variable::factory()->for($this->project)->unset()->create(['name' => 'villain']);
    $doc = Doc::factory()->for($this->project)->published()->create(['body' => '<p>Enter [villain].</p>']);

    $this->getJson('/api/v1/docs/'.$doc->reference)
        ->assertOk()
        ->assertJsonPath('data.body', '<p>Enter [villain].</p>')
        ->assertJsonPath('data.variables.0', ['name' => 'villain', 'value' => null]);
});

it('leaves the sidecar empty when the content names nothing', function () {
    Sanctum::actingAs($this->member, ['read']);

    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create(['description' => '<p>Nothing bracketed.</p>']);

    $this->getJson('/api/v1/tasks/'.$task->reference)
        ->assertOk()
        ->assertJsonCount(0, 'data.variables');
});
