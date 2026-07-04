<?php

use App\Actions\ChangeTaskStatus;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The audit layer adds a sink fan-out and an outbox INSERT to every recorded
 * action. That per-event cost must stay constant: the same status change on a
 * small and a large project must issue the same number of queries, or the
 * emit path has picked up an N+1.
 */
it('audits a status change with a flat query budget regardless of project size', function () {
    $this->actingAs(User::factory()->create());

    $queriesToChangeStatus = function (int $projectSize): int {
        $project = Project::factory()->withMembers([auth()->user()])->create();
        Task::factory()->count($projectSize)->for($project)->create();
        $task = Task::factory()->for($project)->status(Status::ToDo)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ChangeTaskStatus::class)->handle($task, Status::InProgress);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesToChangeStatus(20))->toBeLessThanOrEqual($queriesToChangeStatus(2));
});
