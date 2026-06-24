<?php

use App\Enums\Status;
use App\Livewire\Projects\ProjectShow;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A Done task in $project whose completion is $daysAgo days in the past.
 */
function doneTaskCompletedDaysAgo(Project $project, int $daysAgo): Task
{
    $task = Task::factory()->for($project)->status(Status::Done)->create();
    $task->forceFill(['completed_at' => Carbon::now()->subDays($daysAgo)])->save();

    return $task;
}

it('stamps completed_at when a task enters Done and clears it when it leaves', function () {
    $task = Task::factory()->status(Status::ToDo)->create();
    expect($task->completed_at)->toBeNull();

    $task->status = Status::Done;
    $task->save();
    expect($task->fresh()->completed_at)->not->toBeNull();

    $task->status = Status::ToDo;
    $task->save();
    expect($task->fresh()->completed_at)->toBeNull();
});

it('archives a task done past the global threshold', function () {
    $project = Project::factory()->create(); // inherits the global default (30)
    $old = doneTaskCompletedDaysAgo($project, 40);
    $recent = doneTaskCompletedDaysAgo($project, 5);

    Artisan::call('tasks:auto-archive');

    expect($old->fresh()->isArchived())->toBeTrue()
        ->and($recent->fresh()->isArchived())->toBeFalse();
});

it('never archives a task that is not Done', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->status(Status::ToDo)->create();
    // Make it look old, even though it isn't Done.
    $task->forceFill(['completed_at' => Carbon::now()->subDays(99)])->save();

    Artisan::call('tasks:auto-archive');

    expect($task->fresh()->isArchived())->toBeFalse();
});

it('respects a per-project threshold override', function () {
    $project = Project::factory()->create(['auto_archive_days' => 7]);
    $past = doneTaskCompletedDaysAgo($project, 10);
    $within = doneTaskCompletedDaysAgo($project, 5);

    Artisan::call('tasks:auto-archive');

    expect($past->fresh()->isArchived())->toBeTrue()
        ->and($within->fresh()->isArchived())->toBeFalse();
});

it('disables auto-archiving for a project set to 0', function () {
    $project = Project::factory()->create(['auto_archive_days' => 0]);
    $task = doneTaskCompletedDaysAgo($project, 365);

    Artisan::call('tasks:auto-archive');

    expect($task->fresh()->isArchived())->toBeFalse();
});

it('saves the per-project auto-archive threshold from the settings form', function () {
    $admin = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $admin, 'admin');

    Livewire::actingAs($admin)
        ->test(ProjectShow::class, ['short_name' => 'ABC'])
        ->call('edit')
        ->set('autoArchiveDays', 14)
        ->call('save');

    expect($project->fresh()->auto_archive_days)->toBe(14);
});

it('resolves the effective threshold from project and global config', function () {
    config()->set('kanvigo.tasks.auto_archive_days', 30);

    expect(Project::factory()->create()->autoArchiveThresholdDays())->toBe(30)
        ->and(Project::factory()->create(['auto_archive_days' => 7])->autoArchiveThresholdDays())->toBe(7)
        ->and(Project::factory()->create(['auto_archive_days' => 0])->autoArchiveThresholdDays())->toBeNull();
});
