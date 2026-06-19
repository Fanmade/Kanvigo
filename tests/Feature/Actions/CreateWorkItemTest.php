<?php

use App\Actions\CreateStory;
use App\Actions\CreateTask;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a story with explicit attributes', function () {
    $project = Project::factory()->create();

    $story = app(CreateStory::class)->handle(
        $project,
        'Launch plan',
        'Roll out v2',
        Priority::High,
        '2026-09-01',
    );

    expect($story->project_id)->toBe($project->id)
        ->and($story->title)->toBe('Launch plan')
        ->and($story->description)->toBe('Roll out v2')
        ->and($story->priority)->toBe(Priority::High)
        ->and($story->due_date->format('Y-m-d'))->toBe('2026-09-01');
});

it('defaults a story priority and due date when omitted', function () {
    $project = Project::factory()->create();

    $story = app(CreateStory::class)->handle($project, 'Bare story');

    expect($story->priority)->toBe(Priority::default())
        ->and($story->due_date)->toBeNull()
        ->and($story->description)->toBeNull();
});

it('creates a task with explicit attributes', function () {
    $story = Story::factory()->priority(Priority::Low)->create();

    $task = app(CreateTask::class)->handle(
        $story,
        'Ship it',
        'Deploy to prod',
        Priority::Highest,
        Status::ToDo,
        '2026-10-15',
    );

    expect($task->story_id)->toBe($story->id)
        ->and($task->title)->toBe('Ship it')
        ->and($task->priority)->toBe(Priority::Highest)
        ->and($task->status)->toBe(Status::ToDo)
        ->and($task->due_date->format('Y-m-d'))->toBe('2026-10-15');
});

it('inherits the story priority and defaults status to Planned when omitted', function () {
    $story = Story::factory()->priority(Priority::High)->create();

    $task = app(CreateTask::class)->handle($story, 'Inherited task');

    expect($task->priority)->toBe(Priority::High)
        ->and($task->status)->toBe(Status::Planned)
        ->and($task->due_date)->toBeNull();
});
