<?php

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    // The collision-simulation tests register a one-off `creating` listener; drop it so
    // it can't leak into later tests. Task has no other `creating` listeners we add here.
    Task::getEventDispatcher()->forget('eloquent.creating: '.Task::class);
});

it('assigns sequential numbers per project scope', function () {
    $project = Project::factory()->create();

    $tasks = Task::factory()->for($project)->count(3)->create();

    expect($tasks->pluck('task_number')->all())->toBe([1, 2, 3]);
});

it('numbers each project independently', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();

    Task::factory()->for($a)->count(2)->create();
    $first = Task::factory()->for($b)->create();

    // The second project starts its own sequence at 1, not 3.
    expect($first->task_number)->toBe(1);
});

it('retries with a fresh number when a sibling takes the derived number mid-insert', function () {
    $project = Project::factory()->create();
    Task::factory()->for($project)->create(); // task_number 1

    $stole = false;
    Task::creating(function (Task $task) use ($project, &$stole) {
        if ($stole) {
            return;
        }
        $stole = true;

        // Simulate a concurrent create that grabbed the same number first.
        DB::table('tasks')->insert([
            'project_id' => $project->id,
            'task_number' => $task->task_number,
            'title' => 'race winner',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $mine = Task::factory()->for($project)->create(['title' => 'mine']);

    // Number 2 was stolen, so the retry must land on 3 — never a duplicate.
    expect($mine->task_number)->toBe(3)
        ->and(Task::where('project_id', $project->id)->where('task_number', 2)->count())->toBe(1);
});

it('gives up and surfaces the unique violation after exhausting attempts', function () {
    $project = Project::factory()->create();
    Task::factory()->for($project)->create(); // task_number 1

    // Always steal the derived number, so every attempt collides.
    Task::creating(function (Task $task) use ($project) {
        DB::table('tasks')->insert([
            'project_id' => $project->id,
            'task_number' => $task->task_number,
            'title' => 'thief',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => Task::factory()->for($project)->create(['title' => 'doomed']))
        ->toThrow(UniqueConstraintViolationException::class);
});
