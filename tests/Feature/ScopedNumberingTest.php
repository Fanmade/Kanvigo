<?php

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('derives the next number without a Postgres-incompatible aggregate lock', function () {
    $project = Project::factory()->create();
    Task::factory()->for($project)->create(); // seed an existing sibling

    DB::enableQueryLog();
    Task::factory()->for($project)->create();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // PostgreSQL rejects "FOR UPDATE ... aggregate" (SQLSTATE 0A000). The lookup that
    // assigns the scoped number must therefore not be an aggregate query. SQLite drops
    // the lock clause but keeps the `max(...) as "aggregate"` projection, so its absence
    // is a reliable, driver-agnostic guard against reintroducing the bug.
    $numbering = $queries->first(static fn (string $sql): bool => str_contains($sql, 'task_number')
        && ! str_starts_with($sql, 'insert'));

    expect($numbering)->not->toBeNull()
        ->and($numbering)->not->toContain('as "aggregate"')
        ->and($numbering)->toContain('order by');
});

it('scopes task numbering independently per project', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();

    $taskA = Task::factory()->for($a)->create();
    $taskB = Task::factory()->for($b)->create();

    expect($taskA->task_number)->toBe(1)
        ->and($taskB->task_number)->toBe(1);
});

it('numbers tasks sequentially within a project starting at 1, across parents', function () {
    $project = Project::factory()->create();
    $parentA = Task::factory()->for($project)->create();
    $parentB = Task::factory()->for($project)->create();

    $first = Task::factory()->for($project)->childOf($parentA)->create();
    $second = Task::factory()->for($project)->childOf($parentB)->create();

    // Numbering is a single flat per-project sequence; two root tasks already
    // took 1 and 2, so the subtasks continue at 3 and 4.
    expect($first->task_number)->toBe(3)
        ->and($second->task_number)->toBe(4);
});

it('assigns the next number as max + 1, not preserving middle gaps', function () {
    $project = Project::factory()->create();

    Task::factory()->for($project)->create(); // 1
    Task::factory()->for($project)->create(); // 2
    $third = Task::factory()->for($project)->create(); // 3
    $third->delete();

    // Highest remaining is 2, so the next task reuses 3 (max + 1).
    $next = Task::factory()->for($project)->create();
    expect($next->task_number)->toBe(3);

    // Deleting a middle number does not backfill it.
    Task::query()->where('project_id', $project->id)->where('task_number', 2)->delete();
    $afterMiddleDelete = Task::factory()->for($project)->create();
    expect($afterMiddleDelete->task_number)->toBe(4);
});

it('enforces unique task numbers per project', function () {
    $project = Project::factory()->create();
    Task::factory()->for($project)->create(); // task_number 1

    // A different task in the same project cannot reuse the project-wide number.
    Task::factory()->for($project)->create(['task_number' => 1]);
})->throws(QueryException::class);

it('builds the public reference string', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    $task = Task::factory()->for($project)->create();

    expect($task->reference)->toBe('XYZ-1');
});
