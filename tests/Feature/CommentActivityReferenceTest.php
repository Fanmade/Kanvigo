<?php

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a comment reference multiple activity entries', function () {
    $task = Task::factory()->for(Project::factory())->create();
    $comment = $task->comments()->create(['body' => '<p>why these?</p>']);

    $first = seedActivity($task, 'status_changed');
    $second = seedActivity($task, 'priority_changed');

    $comment->activities()->attach([$first->id, $second->id]);

    expect($comment->activities()->pluck('activities.id')->all())
        ->toEqualCanonicalizing([$first->id, $second->id]);
});

it('exposes the comments that reference an entry', function () {
    $task = Task::factory()->for(Project::factory())->create();
    $entry = seedActivity($task, 'status_changed');

    $a = $task->comments()->create(['body' => '<p>one</p>']);
    $b = $task->comments()->create(['body' => '<p>two</p>']);
    $entry->comments()->attach([$a->id, $b->id]);

    expect($entry->comments()->pluck('comments.id')->all())
        ->toEqualCanonicalizing([$a->id, $b->id]);
});

it('allows referencing an entry from a comment on another task', function () {
    $project = Project::factory()->create();
    $taskA = Task::factory()->for($project)->create();
    $taskB = Task::factory()->for($project)->create();

    $entryOnB = seedActivity($taskB, 'status_changed');
    $commentOnA = $taskA->comments()->create(['body' => '<p>cross-task</p>']);

    $commentOnA->activities()->attach($entryOnB->id);

    expect($commentOnA->activities()->first()->is($entryOnB))->toBeTrue()
        ->and($entryOnB->comments()->first()->is($commentOnA))->toBeTrue();
});

it('drops the reference link when the comment is deleted', function () {
    $task = Task::factory()->for(Project::factory())->create();
    $entry = seedActivity($task, 'status_changed');
    $comment = $task->comments()->create(['body' => '<p>bye</p>']);
    $comment->activities()->attach($entry->id);

    $comment->delete();

    expect($entry->comments()->count())->toBe(0);
});

it('drops the reference link when the activity is deleted', function () {
    $task = Task::factory()->for(Project::factory())->create();
    $entry = seedActivity($task, 'status_changed');
    $comment = $task->comments()->create(['body' => '<p>hi</p>']);
    $comment->activities()->attach($entry->id);

    $entry->delete();

    expect($comment->activities()->count())->toBe(0);
});

it('keeps a reference unique per comment and entry', function () {
    $task = Task::factory()->for(Project::factory())->create();
    $entry = seedActivity($task, 'status_changed');
    $comment = $task->comments()->create(['body' => '<p>dup</p>']);

    $comment->activities()->syncWithoutDetaching($entry->id);
    $comment->activities()->syncWithoutDetaching($entry->id);

    expect($comment->activities()->count())->toBe(1);
});
