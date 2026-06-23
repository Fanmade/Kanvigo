<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->create();
});

it('resolves the project overview at /{short_name}', function () {
    actingAs($this->member)->get('/ABC')->assertOk()->assertSee($this->project->title);
});

it('resolves the project board at /{short_name}/board', function () {
    actingAs($this->member)->get('/ABC/board')->assertOk();
});

it('resolves the global board at /board', function () {
    actingAs($this->member)->get('/board')->assertOk();
});

it('resolves a task at /{short_name}-{task_number}', function () {
    actingAs($this->member)->get('/ABC-1')->assertOk()->assertSee($this->task->title);
});

it('forbids non-members from viewing a project and its board', function () {
    $stranger = User::factory()->create();
    actingAs($stranger)->get('/ABC')->assertForbidden();
    actingAs($stranger)->get('/ABC/board')->assertForbidden();
});

it('keeps reserved routes working', function () {
    actingAs($this->member)->get('/dashboard')->assertOk();
    actingAs($this->member)->get('/settings/profile')->assertOk();
    actingAs($this->member)->get('/projects')->assertOk();
    actingAs($this->member)->get('/board')->assertOk();
});

it('does not match lowercase short names', function () {
    actingAs($this->member)->get('/abc')->assertNotFound();
});

it('does not match short names longer than four letters', function () {
    actingAs($this->member)->get('/ABCDE')->assertNotFound();
});

it('returns 404 for a non-existent task number', function () {
    actingAs($this->member)->get('/ABC-9')->assertNotFound();
});
