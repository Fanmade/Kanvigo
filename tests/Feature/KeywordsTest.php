<?php

use App\Livewire\Tasks\TaskView;
use App\Models\Keyword;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('attaches comma-separated keywords, trimming and de-duplicating', function () {
    $task = Task::factory()->create();

    $task->syncKeywords('bug,  urgent , Bug');

    expect($task->keywords()->count())->toBe(2)
        ->and(Keyword::count())->toBe(2);
});

it('reuses the same keyword across stories and tasks', function () {
    $task = Task::factory()->create();
    $story = Story::factory()->create();

    $task->syncKeywords('shared');
    $story->syncKeywords('shared');

    expect(Keyword::where('name', 'shared')->count())->toBe(1)
        ->and($task->keywords()->count())->toBe(1)
        ->and($story->keywords()->count())->toBe(1);
});

it('detaches keywords removed from the list', function () {
    $task = Task::factory()->create();

    $task->syncKeywords('a, b, c');
    $task->syncKeywords('a');

    expect($task->keywords()->pluck('name')->all())->toBe(['a']);
});

it('saves keywords through the task view and logs the change', function () {
    $member = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($member);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    Livewire::actingAs($member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'story_number' => $story->story_number,
            'task_number' => $task->task_number,
        ])
        ->call('edit')
        ->set('keywords', 'frontend, ux')
        ->call('save');

    expect($task->fresh()->keywords()->pluck('name')->all())->toBe(['frontend', 'ux'])
        ->and($task->activities()->where('action', 'keywords_changed')->count())->toBe(1);
});
