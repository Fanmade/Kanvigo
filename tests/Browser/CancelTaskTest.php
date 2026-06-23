<?php

use App\Enums\CancelReason;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('cancels a task with a reason through the UI, warning about open subtasks', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $task = Task::factory()->for($project)->status(Status::ToDo)->create();
    Task::factory()->for($project)->childOf($task)->status(Status::ToDo)->create();

    $this->actingAs($user);

    $page = visit("/{$project->short_name}-{$task->task_number}");

    $page->click('@task-actions') // open the actions menu that now holds Cancel
        ->click('@cancel-task')
        ->waitForText('Cancel this task?') // barrier: cancel modal opened (Livewire round-trip)
        ->assertVisible('@cancel-subtree-warning')
        ->assertSeeIn('@cancel-subtree-warning', 'open subtask')
        ->select('@cancel-reason', CancelReason::Duplicate->value)
        ->fill('@cancel-message', 'Superseded by ABC-9')
        ->click('@confirm-cancel')
        ->waitForText('This task was canceled.') // barrier: cancellation applied
        ->assertVisible('@canceled-banner')
        ->assertSeeIn('@cancel-reason-badge', 'Duplicate')
        ->assertSeeIn('@canceled-banner', 'Superseded by ABC-9')
        ->assertMissing('@task-actions')
        ->assertNoJavascriptErrors();

    expect($task->fresh()->status)->toBe(Status::Canceled);
});

it('reopens a canceled task through the UI', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $task = Task::factory()->for($project)->canceled(CancelReason::WontFix, 'No longer needed')->create();

    $this->actingAs($user);

    $page = visit("/{$project->short_name}-{$task->task_number}");

    $page->assertVisible('@canceled-banner')
        ->click('@reopen-task')
        ->waitForText('Planned') // barrier: reopened, status is now Planned
        ->assertMissing('@canceled-banner')
        ->assertVisible('@task-actions')
        ->assertNoJavascriptErrors();

    expect($task->fresh()->status)->toBe(Status::Planned);
});
