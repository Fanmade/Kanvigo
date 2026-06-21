<?php

use App\Actions\CreateTask;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a task with explicit attributes', function () {
    $project = Project::factory()->create();

    $task = app(CreateTask::class)->handle(
        $project,
        'Ship it',
        'Deploy to prod',
        Priority::Highest,
        Status::ToDo,
        '2026-10-15',
    );

    expect($task->project_id)->toBe($project->id)
        ->and($task->parent_id)->toBeNull()
        ->and($task->title)->toBe('Ship it')
        ->and($task->description)->toBe('Deploy to prod')
        ->and($task->priority)->toBe(Priority::Highest)
        ->and($task->status)->toBe(Status::ToDo)
        ->and($task->due_date->format('Y-m-d'))->toBe('2026-10-15');
});

it('defaults the priority and status and leaves description and due date empty when omitted', function () {
    $project = Project::factory()->create();

    $task = app(CreateTask::class)->handle($project, 'Bare task');

    expect($task->priority)->toBe(Priority::default())
        ->and($task->status)->toBe(Status::Planned)
        ->and($task->due_date)->toBeNull()
        ->and($task->description)->toBeNull();
});

it('nests a task under a parent in the same project', function () {
    $project = Project::factory()->create();
    $parent = Task::factory()->for($project)->create();

    $task = app(CreateTask::class)->handle(
        $project,
        'Subtask',
        null,
        null,
        null,
        null,
        $parent,
    );

    expect($task->project_id)->toBe($project->id)
        ->and($task->parent_id)->toBe($parent->id);
});
