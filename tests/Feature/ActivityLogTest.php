<?php

use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('logs creation of projects, stories and tasks', function () {
    $project = Project::factory()->create();
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    expect($project->activities()->where('action', 'created')->count())->toBe(1)
        ->and($story->activities()->where('action', 'created')->count())->toBe(1)
        ->and($task->activities()->where('action', 'created')->count())->toBe(1);
});

it('logs assignee changes with the acting user', function () {
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
        ->set('assigneeIds', [$member->id]);

    $activity = $task->activities()->where('action', 'assignee_changed')->first();

    expect($task->assignees()->count())->toBe(1)
        ->and($activity)->not->toBeNull()
        ->and($activity->user_id)->toBe($member->id);
});
