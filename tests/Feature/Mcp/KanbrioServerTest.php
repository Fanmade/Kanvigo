<?php

use App\Enums\Status;
use App\Mcp\Servers\KanbrioServer;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetStoryTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListStoriesTool;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->member])->create(['short_name' => 'ABC', 'title' => 'Apollo']);
    $this->story = Story::factory()->for($this->project)->create(['title' => 'First story']);
    $this->task = Task::factory()->for($this->story)->status(Status::ToDo)->create(['title' => 'First task']);
});

it('lists projects the user is a member of', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(ListProjectsTool::class)
        ->assertOk()
        ->assertSee('Apollo')
        ->assertSee('ABC');
});

it('omits projects the user is not a member of from the list', function () {
    Project::factory()->create(['short_name' => 'XYZ', 'title' => 'Secret Project']);

    KanbrioServer::actingAs($this->member)
        ->tool(ListProjectsTool::class)
        ->assertOk()
        ->assertDontSee('Secret Project');
});

it('gets a project the member can view', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(GetProjectTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertSee('Apollo')
        ->assertSee('ABC1');
});

it('errors when getting a project the user is not a member of', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);

    KanbrioServer::actingAs($this->member)
        ->tool(GetProjectTool::class, ['short_name' => $project->short_name])
        ->assertHasErrors();
});

it('errors when getting a project that does not exist', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(GetProjectTool::class, ['short_name' => 'NOPE'])
        ->assertHasErrors();
});

it('errors when the short_name argument is missing', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(GetProjectTool::class, [])
        ->assertHasErrors();
});

it('lists the stories of a project', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(ListStoriesTool::class, ['short_name' => 'ABC'])
        ->assertOk()
        ->assertSee('First story')
        ->assertSee('ABC1');
});

it('errors listing stories of an inaccessible project', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);

    KanbrioServer::actingAs($this->member)
        ->tool(ListStoriesTool::class, ['short_name' => $project->short_name])
        ->assertHasErrors();
});

it('gets a story by reference including its tasks', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(GetStoryTool::class, ['reference' => 'ABC1'])
        ->assertOk()
        ->assertSee('First story')
        ->assertSee('ABC-1')
        ->assertSee('First task');
});

it('errors getting a story the user cannot view', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    Story::factory()->for($project)->create();

    KanbrioServer::actingAs($this->member)
        ->tool(GetStoryTool::class, ['reference' => 'XYZ1'])
        ->assertHasErrors();
});

it('errors getting a story with a malformed reference', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(GetStoryTool::class, ['reference' => 'not-a-ref'])
        ->assertHasErrors();
});

it('lists the tasks of a story', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(ListTasksTool::class, ['reference' => 'ABC1'])
        ->assertOk()
        ->assertSee('First task')
        ->assertSee('ABC-1');
});

it('filters tasks by status', function () {
    Task::factory()->for($this->story)->status(Status::Done)->create(['title' => 'Completed task']);

    KanbrioServer::actingAs($this->member)
        ->tool(ListTasksTool::class, ['reference' => 'ABC1', 'status' => Status::Done->value])
        ->assertOk()
        ->assertSee('Completed task')
        ->assertDontSee('First task');
});

it('errors filtering tasks with an invalid status', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(ListTasksTool::class, ['reference' => 'ABC1', 'status' => 'Bogus'])
        ->assertHasErrors();
});

it('gets a task by reference', function () {
    $this->task->assignees()->attach($this->member);

    KanbrioServer::actingAs($this->member)
        ->tool(GetTaskTool::class, ['reference' => 'ABC-1'])
        ->assertOk()
        ->assertSee('First task')
        ->assertSee('ToDo')
        ->assertSee($this->member->email);
});

it('errors getting a task the user cannot view', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    $story = Story::factory()->for($project)->create();
    Task::factory()->for($story)->create();

    KanbrioServer::actingAs($this->member)
        ->tool(GetTaskTool::class, ['reference' => 'XYZ-1'])
        ->assertHasErrors();
});

it('errors getting a task with a malformed reference', function () {
    KanbrioServer::actingAs($this->member)
        ->tool(GetTaskTool::class, ['reference' => 'ABC1'])
        ->assertHasErrors();
});
