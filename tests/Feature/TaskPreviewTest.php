<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves a task preview to someone who can see the task', function () {
    $member = User::factory()->create(['name' => 'Dana']);
    $project = Project::factory()->create();
    joinProject($project, $member);

    $task = Task::factory()->for($project)->status(Status::InProgress)->create(['title' => 'Wire the preview']);
    $task->assignees()->attach($member->id);
    Task::factory()->for($project)->childOf($task)->status(Status::Done)->create();
    Task::factory()->for($project)->childOf($task)->create();

    $this->actingAs($member)
        ->getJson(route('task.preview', ['short_name' => $project->short_name, 'task_number' => $task->task_number]))
        ->assertOk()
        ->assertJson([
            'reference' => $task->reference,
            'title' => 'Wire the preview',
            'status' => 'In progress',
            'assignees' => ['Dana'],
            'progress' => ['done' => 1, 'total' => 2],
            'is_blocked' => false,
        ]);
});

it('localizes the progress label', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $member);

    $task = Task::factory()->for($project)->create();
    Task::factory()->for($project)->childOf($task)->status(Status::Done)->create();
    Task::factory()->for($project)->childOf($task)->create();

    $this->actingAs($member)
        ->withSession(['locale' => 'de'])
        ->getJson(route('task.preview', ['short_name' => $project->short_name, 'task_number' => $task->task_number]))
        ->assertOk()
        ->assertJsonPath('progress.label', '1/2 erledigt');
});

it('reports a reference as blocked while a blocker is open', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $member);

    $task = Task::factory()->for($project)->create();
    $blocker = Task::factory()->for($project)->status(Status::ToDo)->create();
    $task->addBlocker($blocker);

    $this->actingAs($member)
        ->getJson(route('task.preview', ['short_name' => $project->short_name, 'task_number' => $task->task_number]))
        ->assertOk()
        ->assertJsonPath('is_blocked', true);
});

it('forbids a task preview to someone without access', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('task.preview', ['short_name' => $project->short_name, 'task_number' => $task->task_number]))
        ->assertForbidden();
});

it('returns 404 for an unknown reference', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $member);

    $this->actingAs($member)
        ->getJson(route('task.preview', ['short_name' => $project->short_name, 'task_number' => 9999]))
        ->assertNotFound();
});
