<?php

use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('numbers stories sequentially within a project starting at 1', function () {
    $project = Project::factory()->create();

    $first = Story::factory()->for($project)->create();
    $second = Story::factory()->for($project)->create();

    expect($first->story_number)->toBe(1)
        ->and($second->story_number)->toBe(2);
});

it('scopes story numbering independently per project', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();

    $storyA = Story::factory()->for($a)->create();
    $storyB = Story::factory()->for($b)->create();

    expect($storyA->story_number)->toBe(1)
        ->and($storyB->story_number)->toBe(1);
});

it('numbers tasks sequentially within a story starting at 1', function () {
    $story = Story::factory()->create();

    $first = Task::factory()->for($story)->create();
    $second = Task::factory()->for($story)->create();

    expect($first->task_number)->toBe(1)
        ->and($second->task_number)->toBe(2);
});

it('assigns the next number as max + 1, not preserving middle gaps', function () {
    $story = Story::factory()->create();

    Task::factory()->for($story)->create(); // 1
    Task::factory()->for($story)->create(); // 2
    $third = Task::factory()->for($story)->create(); // 3
    $third->delete();

    // Highest remaining is 2, so the next task reuses 3 (max + 1).
    $next = Task::factory()->for($story)->create();
    expect($next->task_number)->toBe(3);

    // Deleting a middle number does not backfill it.
    Task::query()->where('story_id', $story->id)->where('task_number', 2)->delete();
    $afterMiddleDelete = Task::factory()->for($story)->create();
    expect($afterMiddleDelete->task_number)->toBe(4);
});

it('enforces unique story numbers per project', function () {
    $project = Project::factory()->create();
    Story::factory()->for($project)->create(); // story_number 1

    Story::factory()->for($project)->create(['story_number' => 1]);
})->throws(QueryException::class);

it('enforces unique task numbers per story', function () {
    $story = Story::factory()->create();
    Task::factory()->for($story)->create(); // task_number 1

    Task::factory()->for($story)->create(['task_number' => 1]);
})->throws(QueryException::class);

it('builds the public reference strings', function () {
    $project = Project::factory()->create(['short_name' => 'XYZ']);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    expect($story->reference)->toBe('XYZ1')
        ->and($task->reference)->toBe('XYZ1-1');
});
