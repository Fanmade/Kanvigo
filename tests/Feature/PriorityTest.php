<?php

use App\Enums\Priority;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the middle level as the default priority', function () {
    expect(Priority::default())->toBe(Priority::Medium)
        ->and(Priority::Medium->value)->toBe(3);
});

it('defaults a root task without an explicit priority to medium', function () {
    $project = Project::factory()->create();

    $task = $project->tasks()->create(['title' => 'No priority given']);

    expect($task->refresh()->priority)->toBe(Priority::Medium);
});

it('casts the priority column to the enum', function () {
    $task = Task::factory()->priority(Priority::High)->create();

    expect($task->refresh()->priority)->toBe(Priority::High);
});

it('inherits the parent task priority when a subtask is created without one', function () {
    $parent = Task::factory()->priority(Priority::Highest)->create();

    $subtask = Task::factory()->for($parent->project)->childOf($parent)->create();

    expect($subtask->refresh()->priority)->toBe(Priority::Highest);
});

it('lets a subtask override the inherited priority', function () {
    $parent = Task::factory()->priority(Priority::Highest)->create();

    $subtask = Task::factory()->for($parent->project)->childOf($parent)->priority(Priority::Lowest)->create();

    expect($subtask->refresh()->priority)->toBe(Priority::Lowest);
});

it('sorts by the integer-backed priority column', function () {
    $project = Project::factory()->create();

    Task::factory()->for($project)->priority(Priority::High)->create();
    Task::factory()->for($project)->priority(Priority::Lowest)->create();
    Task::factory()->for($project)->priority(Priority::Highest)->create();

    $ordered = Task::query()->where('project_id', $project->id)->orderByDesc('priority')->get()
        ->map(static fn (Task $task): Priority => $task->priority)
        ->all();

    expect($ordered[0])->toBe(Priority::Highest)
        ->and($ordered[1])->toBe(Priority::High)
        ->and(end($ordered))->toBe(Priority::Lowest);
});
