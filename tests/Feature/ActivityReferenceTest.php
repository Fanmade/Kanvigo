<?php

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Support\ReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('numbers activities per subject starting at 1', function () {
    $task = Task::factory()->for(Project::factory())->create();

    // The "created" entry is the first.
    expect($task->activities()->min('sequence'))->toBe(1);

    $second = seedActivity($task, 'status_changed');
    $third = seedActivity($task, 'priority_changed');

    expect($second->sequence)->toBe(2)
        ->and($third->sequence)->toBe(3);
});

it('numbers each subject independently', function () {
    $project = Project::factory()->create();
    $taskA = Task::factory()->for($project)->create();
    $taskB = Task::factory()->for($project)->create();

    $a = seedActivity($taskA, 'status_changed');
    $b = seedActivity($taskB, 'status_changed');

    // Both subjects' own "created" entry is sequence 1, so the next is 2 on each.
    expect($a->sequence)->toBe(2)
        ->and($b->sequence)->toBe(2);
});

it('formats a self-describing reference for task-subject activities', function () {
    $project = Project::factory()->create(['short_name' => 'KAN']);
    $task = Task::factory()->for($project)->create();

    $entry = seedActivity($task, 'status_changed');

    expect($entry->reference)->toBe("KAN-{$task->task_number}-log-{$entry->sequence}");
});

it('returns no reference for non-task subjects', function () {
    $project = Project::factory()->create();

    $entry = $project->activities()->where('action', 'created')->first();

    expect($entry->reference)->toBeNull();
});

it('resolves an activity-log reference back to its entry', function () {
    $project = Project::factory()->create(['short_name' => 'KAN']);
    $task = Task::factory()->for($project)->create();
    $entry = seedActivity($task, 'status_changed');

    $resolved = ReferenceResolver::activity($entry->reference);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($entry->id);
});

it('resolves case-insensitively and trims whitespace', function () {
    $project = Project::factory()->create(['short_name' => 'KAN']);
    $task = Task::factory()->for($project)->create();
    $entry = seedActivity($task, 'status_changed');

    $resolved = ReferenceResolver::activity("  kan-{$task->task_number}-LoG-{$entry->sequence}  ");

    expect($resolved?->id)->toBe($entry->id);
});

it('returns null for malformed or unknown activity references', function () {
    $project = Project::factory()->create(['short_name' => 'KAN']);
    $task = Task::factory()->for($project)->create();

    expect(ReferenceResolver::activity('KAN-1'))->toBeNull()         // a task reference, not a log one
        ->and(ReferenceResolver::activity('KAN'))->toBeNull()        // a project reference
        ->and(ReferenceResolver::activity('not a ref'))->toBeNull()
        ->and(ReferenceResolver::activity('ZZ-1-log-1'))->toBeNull() // unknown project
        ->and(ReferenceResolver::activity("KAN-{$task->task_number}-log-999"))->toBeNull(); // unknown sequence
});

it('backfills existing rows by creation order within each subject', function () {
    $project = Project::factory()->create();
    $taskA = Task::factory()->for($project)->create();
    $taskB = Task::factory()->for($project)->create();

    foreach (['status_changed', 'priority_changed'] as $action) {
        seedActivity($taskA, $action);
        seedActivity($taskB, $action);
    }

    $expected = Activity::query()->pluck('sequence', 'id');

    // Simulate the pre-migration state, then re-run the backfill statement.
    DB::table('activities')->update(['sequence' => null]);
    DB::statement(<<<'SQL'
        UPDATE activities SET sequence = (
            SELECT COUNT(*) FROM activities AS earlier
            WHERE earlier.subject_type = activities.subject_type
              AND earlier.subject_id = activities.subject_id
              AND earlier.id <= activities.id
        )
    SQL);

    expect(Activity::query()->pluck('sequence', 'id')->all())->toBe($expected->all());
});
