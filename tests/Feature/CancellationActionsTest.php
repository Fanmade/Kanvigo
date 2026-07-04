<?php

use App\Actions\CancelTask;
use App\Enums\CancelReason;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ItemActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->actor])->create(['short_name' => 'ABC']);
    $this->task = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $this->actingAs($this->actor);
});

it('cancels a task with a reason and message, recording a canceled activity', function () {
    $this->task->cancel(CancelReason::Duplicate, '  Same as ABC-1  ');

    $activity = $this->task->activities()->where('action', 'canceled')->firstOrFail();
    $fresh = $this->task->fresh();

    expect($fresh->status)->toBe(Status::Canceled)
        ->and($fresh->isCanceled())->toBeTrue()
        ->and($fresh->cancel_reason)->toBe(CancelReason::Duplicate)
        ->and($fresh->cancel_message)->toBe('Same as ABC-1') // trimmed
        ->and($activity->action)->toBe('canceled');

    expect(json_decode((string) $activity->new_value, true))
        ->toBe(['reason' => 'duplicate', 'message' => 'Same as ABC-1']);
});

it('treats a blank cancel message as no message', function () {
    $this->task->cancel(CancelReason::WontFix, '   ');

    expect($this->task->fresh()->cancel_message)->toBeNull();
});

it('does not cancel an already-canceled task again', function () {
    $task = Task::factory()->for($this->project)->canceled(CancelReason::Deprecated)->create();

    expect($task->cancel(CancelReason::WontFix))->toBeFalse()
        ->and($task->fresh()->cancel_reason)->toBe(CancelReason::Deprecated);
});

it('reopens a canceled task back to Planned, clearing the cancellation', function () {
    $task = Task::factory()->for($this->project)->canceled(CancelReason::Duplicate, 'note')->create();

    $task->reopen();

    $activity = $task->activities()->where('action', 'reopened')->firstOrFail();
    $fresh = $task->fresh();

    expect($fresh->status)->toBe(Status::Planned)
        ->and($fresh->isCanceled())->toBeFalse()
        ->and($fresh->canceled_at)->toBeNull()
        ->and($fresh->cancel_reason)->toBeNull()
        ->and($fresh->cancel_message)->toBeNull()
        ->and($activity->action)->toBe('reopened');
});

it('does not reopen a task that is not canceled', function () {
    expect($this->task->reopen())->toBeFalse();
});

it('notifies subscribers, but not the actor, when a task is canceled', function () {
    Notification::fake();

    $watcher = User::factory()->create();
    joinProject($this->project, $watcher);
    $this->task->subscribe($watcher);
    $this->task->subscribe($this->actor);

    $this->task->cancel(CancelReason::WontFix);

    Notification::assertSentTo($watcher, ItemActivity::class);
    Notification::assertNotSentTo($this->actor, ItemActivity::class);
});

it('cancels a parent together with its open subtree, in one transaction', function () {
    $parent = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $openChild = Task::factory()->for($this->project)->childOf($parent)->status(Status::InProgress)->create();
    $openGrandchild = Task::factory()->for($this->project)->childOf($openChild)->status(Status::Planned)->create();

    $cascaded = app(CancelTask::class)->cancel($parent, CancelReason::Deprecated, 'End of life');

    expect($cascaded)->toBe(2)
        ->and($parent->fresh()->status)->toBe(Status::Canceled)
        ->and($parent->fresh()->cancel_message)->toBe('End of life')
        ->and($openChild->fresh()->isCanceled())->toBeTrue()
        ->and($openChild->fresh()->cancel_reason)->toBe(CancelReason::Deprecated)
        ->and($openChild->fresh()->cancel_message)->toBeNull() // the message stays on the parent only
        ->and($openGrandchild->fresh()->isCanceled())->toBeTrue();
});

it('leaves already-terminal subtasks untouched when cancelling a parent', function () {
    $parent = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $done = Task::factory()->for($this->project)->childOf($parent)->status(Status::Done)->create();
    $preCanceled = Task::factory()->for($this->project)->childOf($parent)->canceled(CancelReason::Duplicate)->create();
    $open = Task::factory()->for($this->project)->childOf($parent)->status(Status::ToDo)->create();

    $cascaded = app(CancelTask::class)->cancel($parent, CancelReason::WontFix);

    expect($cascaded)->toBe(1) // only the open child
        ->and($done->fresh()->status)->toBe(Status::Done)
        ->and($done->fresh()->isCanceled())->toBeFalse()
        ->and($preCanceled->fresh()->cancel_reason)->toBe(CancelReason::Duplicate) // unchanged
        ->and($open->fresh()->isCanceled())->toBeTrue();
});

it('reports how many open subtasks a cancel would cascade to', function () {
    $parent = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    Task::factory()->for($this->project)->childOf($parent)->status(Status::ToDo)->create();
    Task::factory()->for($this->project)->childOf($parent)->status(Status::Done)->create();

    expect($parent->openSubtaskCount())->toBe(1);
});

it('reopens only the parent, leaving cascade-canceled subtasks canceled', function () {
    $parent = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $child = Task::factory()->for($this->project)->childOf($parent)->status(Status::ToDo)->create();
    app(CancelTask::class)->cancel($parent, CancelReason::WontFix);

    app(CancelTask::class)->reopen($parent);

    expect($parent->fresh()->status)->toBe(Status::Planned)
        ->and($child->fresh()->isCanceled())->toBeTrue();
});

it('forbids a non-member from updating (and thus cancelling) a task', function () {
    $outsider = User::factory()->create();

    expect($outsider->can('update', $this->task))->toBeFalse()
        ->and($this->actor->can('update', $this->task))->toBeTrue();
});
