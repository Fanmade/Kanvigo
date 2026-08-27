<?php

use App\Actions\SetWaitingOn;
use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->awaited = User::factory()->create();
    Sanctum::actingAs($this->user, ['read', 'write']);
    $this->project = Project::factory()->withMembers([$this->user, $this->awaited])->create(['short_name' => 'ABC']);
    $this->task = Task::factory()->for($this->project)->create();
});

it('sets the waiting-on member through update-task', function () {
    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $this->task->reference,
        'waiting_on' => $this->awaited->public_id,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('waiting_on.id', $this->awaited->public_id)
            ->where('waiting_on.name', $this->awaited->name)
            ->etc());

    expect($this->task->fresh()->waiting_on_user_id)->toBe($this->awaited->id);
});

it('clears the waiting-on member with null', function () {
    app(SetWaitingOn::class)->handle($this->task, $this->awaited);

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $this->task->reference,
        'waiting_on' => null,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('waiting_on', null)->etc());

    expect($this->task->fresh()->waiting_on_user_id)->toBeNull();
});

it('rejects a user who is not a member of the project', function () {
    $outsider = User::factory()->create();

    KanvigoServer::tool(UpdateTaskTool::class, [
        'reference' => $this->task->reference,
        'waiting_on' => $outsider->public_id,
    ])->assertHasErrors();

    expect($this->task->fresh()->waiting_on_user_id)->toBeNull();
});

it('reports the waiting-on member on get-task', function () {
    app(SetWaitingOn::class)->handle($this->task, $this->awaited);

    KanvigoServer::tool(GetTaskTool::class, ['reference' => $this->task->reference])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('waiting_on.id', $this->awaited->public_id)->etc());
});

it('reports the waiting-on member on list-tasks', function () {
    app(SetWaitingOn::class)->handle($this->task, $this->awaited);

    KanvigoServer::tool(ListTasksTool::class, ['reference' => 'ABC'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('tasks.0.waiting_on.name', $this->awaited->name)->etc());
});

it('reports null on a task nobody is waiting on', function () {
    KanvigoServer::tool(GetTaskTool::class, ['reference' => $this->task->reference])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('waiting_on', null)->etc());
});
