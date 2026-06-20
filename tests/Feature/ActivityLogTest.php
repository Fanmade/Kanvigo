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
            'task_number' => $task->task_number,
        ])
        ->set('assigneeIds', [$member->id]);

    $activity = $task->activities()->where('action', 'assignee_changed')->first();

    expect($task->assignees()->count())->toBe(1)
        ->and($activity)->not->toBeNull()
        ->and($activity->user_id)->toBe($member->id);
});

it('attributes a web-session action to no token', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    $this->actingAs($user);
    $task->recordActivity('status_changed', 'status', 'todo', 'done');

    expect($task->activities()->where('action', 'status_changed')->first()->token_name)->toBeNull();
});

it('attributes a token-driven action to the token name', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    $user->withAccessToken($user->createToken('Claude')->accessToken);
    $this->actingAs($user);

    $task->recordActivity('status_changed', 'status', 'todo', 'done');

    expect($task->activities()->where('action', 'status_changed')->first()->token_name)->toBe('Claude');
});

it('records the names of added and removed task assignees', function () {
    $actor = User::factory()->create();
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach([$actor->id, $alice->id, $bob->id]);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();
    $task->assignees()->attach($alice->id);

    Livewire::actingAs($actor)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $task->task_number,
        ])
        ->set('assigneeIds', [$bob->id]);

    $activity = $task->activities()->where('action', 'assignee_changed')->first();

    expect(json_decode((string) $activity->new_value, true))->toBe(['Bob'])
        ->and(json_decode((string) $activity->old_value, true))->toBe(['Alice']);
});
