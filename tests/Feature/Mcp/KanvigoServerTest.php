<?php

use App\Enums\Status;
use App\Mcp\Servers\KanvigoServer;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->member])->create(['short_name' => 'ABC', 'title' => 'Apollo']);
    $this->task = Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'First task']);
});

it('lists projects the user is a member of', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(ListProjectsTool::class)
        ->assertOk()
        ->assertSee('Apollo')
        ->assertSee('ABC');
});

it('omits projects the user is not a member of from the list', function () {
    Project::factory()->create(['short_name' => 'XYZ', 'title' => 'Secret Project']);

    KanvigoServer::actingAs($this->member)
        ->tool(ListProjectsTool::class)
        ->assertOk()
        ->assertDontSee('Secret Project');
});

it('gets a project the member can view including its tasks', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertSee('Apollo')
        ->assertSee('First task')
        ->assertSee($this->task->reference);
});

it('errors when getting a project the user is not a member of', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);

    KanvigoServer::actingAs($this->member)
        ->tool(GetProjectTool::class, ['short_name' => $project->short_name])
        ->assertHasErrors();
});

it('errors when getting a project that does not exist', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(GetProjectTool::class, ['short_name' => 'NOPE'])
        ->assertHasErrors();
});

it('errors when the short_name argument is missing', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(GetProjectTool::class, [])
        ->assertHasErrors();
});

it('lists the tasks of a project', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(ListTasksTool::class, ['reference' => 'ABC'])
        ->assertOk()
        ->assertSee('First task')
        ->assertSee($this->task->reference);
});

it('errors listing tasks of an inaccessible project', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);

    KanvigoServer::actingAs($this->member)
        ->tool(ListTasksTool::class, ['reference' => $project->short_name])
        ->assertHasErrors();
});

it('filters tasks by status', function () {
    Task::factory()->for($this->project)->status(Status::Done)->create(['title' => 'Completed task']);

    KanvigoServer::actingAs($this->member)
        ->tool(ListTasksTool::class, ['reference' => 'ABC', 'status' => Status::Done->value])
        ->assertOk()
        ->assertSee('Completed task')
        ->assertDontSee('First task');
});

it('errors filtering tasks with an invalid status', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(ListTasksTool::class, ['reference' => 'ABC', 'status' => 'Bogus'])
        ->assertHasErrors();
});

it('gets a task by reference', function () {
    $this->task->assignees()->attach($this->member);

    KanvigoServer::actingAs($this->member)
        ->tool(GetTaskTool::class, ['reference' => $this->task->reference])
        ->assertOk()
        ->assertSee('First task')
        ->assertSee('ToDo')
        ->assertSee($this->member->name);
});

it('errors getting a task the user cannot view', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    $task = Task::factory()->for($project)->create();

    KanvigoServer::actingAs($this->member)
        ->tool(GetTaskTool::class, ['reference' => $task->reference])
        ->assertHasErrors();
});

it('errors getting a task with a malformed reference', function () {
    KanvigoServer::actingAs($this->member)
        ->tool(GetTaskTool::class, ['reference' => 'ABC1'])
        ->assertHasErrors();
});
