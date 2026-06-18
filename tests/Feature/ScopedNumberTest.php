<?php

use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    // The collision-simulation tests register a one-off `creating` listener; drop it so
    // it can't leak into later tests. Story/Task have no other `creating` listeners.
    Story::getEventDispatcher()->forget('eloquent.creating: '.Story::class);
    Task::getEventDispatcher()->forget('eloquent.creating: '.Task::class);
});

it('assigns sequential numbers per parent scope', function () {
    $project = Project::factory()->create();

    $stories = Story::factory()->for($project)->count(3)->create();

    expect($stories->pluck('story_number')->all())->toBe([1, 2, 3]);

    $tasks = Task::factory()->for($stories->first())->count(2)->create();

    expect($tasks->pluck('task_number')->all())->toBe([1, 2]);
});

it('numbers each parent independently', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();

    Story::factory()->for($a)->count(2)->create();
    $first = Story::factory()->for($b)->create();

    // The second project starts its own sequence at 1, not 3.
    expect($first->story_number)->toBe(1);
});

it('retries with a fresh number when a sibling takes the derived number mid-insert', function () {
    $project = Project::factory()->create();
    Story::factory()->for($project)->create(); // story_number 1

    $stole = false;
    Story::creating(function (Story $story) use ($project, &$stole) {
        if ($stole) {
            return;
        }
        $stole = true;

        // Simulate a concurrent create that grabbed the same number first.
        DB::table('stories')->insert([
            'project_id' => $project->id,
            'story_number' => $story->story_number,
            'title' => 'race winner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $mine = Story::factory()->for($project)->create(['title' => 'mine']);

    // Number 2 was stolen, so the retry must land on 3 — never a duplicate.
    expect($mine->story_number)->toBe(3)
        ->and(Story::where('project_id', $project->id)->where('story_number', 2)->count())->toBe(1);
});

it('gives up and surfaces the unique violation after exhausting attempts', function () {
    $project = Project::factory()->create();
    Story::factory()->for($project)->create(); // story_number 1

    // Always steal the derived number, so every attempt collides.
    Story::creating(function (Story $story) use ($project) {
        DB::table('stories')->insert([
            'project_id' => $project->id,
            'story_number' => $story->story_number,
            'title' => 'thief',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => Story::factory()->for($project)->create(['title' => 'doomed']))
        ->toThrow(UniqueConstraintViolationException::class);
});
