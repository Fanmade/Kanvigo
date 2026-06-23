<?php

use App\Enums\CancelReason;
use App\Enums\Status;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);

    $this->task = Task::factory()->for($this->project)->status(Status::ToDo)->create();

    $this->view = fn (Task $task) => Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number]);
});

it('shows the cancel action to a member and opens the confirmation', function () {
    ($this->view)($this->task)
        ->assertSeeHtml('data-test="cancel-task"')
        ->call('confirmCancel')
        ->assertSet('confirmingCancel', true);
});

it('requires a reason to cancel', function () {
    ($this->view)($this->task)
        ->call('confirmCancel')
        ->set('cancelReason', '')
        ->call('cancelTask')
        ->assertHasErrors(['cancelReason' => 'required']);

    expect($this->task->fresh()->isCanceled())->toBeFalse();
});

it('cancels a task with a reason and message, then shows the banner', function () {
    ($this->view)($this->task)
        ->call('confirmCancel')
        ->set('cancelReason', CancelReason::Duplicate->value)
        ->set('cancelMessage', 'Same as ABC-1')
        ->call('cancelTask')
        ->assertHasNoErrors()
        ->assertSet('confirmingCancel', false)
        ->assertSee('This task was canceled.')
        ->assertSee('Duplicate')
        ->assertSee('Same as ABC-1');

    $fresh = $this->task->fresh();

    expect($fresh->status)->toBe(Status::Canceled)
        ->and($fresh->cancel_reason)->toBe(CancelReason::Duplicate)
        ->and($fresh->cancel_message)->toBe('Same as ABC-1');
});

it('warns about, and cancels, the open subtree when cancelling a parent', function () {
    $child = Task::factory()->for($this->project)->childOf($this->task)->status(Status::ToDo)->create();

    ($this->view)($this->task)
        ->call('confirmCancel')
        ->assertSee('This will also cancel 1 open subtask(s) below it.')
        ->set('cancelReason', CancelReason::Deprecated->value)
        ->call('cancelTask');

    expect($this->task->fresh()->isCanceled())->toBeTrue()
        ->and($child->fresh()->isCanceled())->toBeTrue();
});

it('reopens a canceled task back to Planned and drops the banner', function () {
    $this->task->cancel(CancelReason::WontFix);

    ($this->view)($this->task->fresh())
        ->assertSee('This task was canceled.')
        ->assertSee('Reopen')
        ->call('reopenTask')
        ->assertDontSee('This task was canceled.');

    expect($this->task->fresh()->status)->toBe(Status::Planned)
        ->and($this->task->fresh()->isCanceled())->toBeFalse();
});

it('hides the cancel action once the task is canceled', function () {
    $this->task->cancel(CancelReason::WontFix);

    ($this->view)($this->task->fresh())
        ->assertDontSeeHtml('data-test="cancel-task"')
        ->assertSee('Reopen');
});

it('forbids a non-member from viewing (and thus cancelling) the task', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
        ->assertForbidden();
});
