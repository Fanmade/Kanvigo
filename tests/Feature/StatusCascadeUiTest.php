<?php

use App\Actions\ChangeTaskStatus;
use App\Enums\CascadePreference;
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
    $this->project->members()->attach($this->member);

    $this->parent = Task::factory()->for($this->project)->status(Status::InProgress)->create();
    $this->child = Task::factory()->for($this->project)->childOf($this->parent)->status(Status::ToDo)->create();

    $this->view = fn (Task $task) => Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number]);
});

it('holds a Done change behind a modal when there are open subtasks (ask preference)', function () {
    $this->member->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);

    ($this->view)($this->parent)
        ->set('status', Status::Done->value)
        ->assertSet('confirmingCascade', true);

    // Nothing changes until the prompt is resolved.
    expect($this->parent->fresh()->status)->toBe(Status::InProgress)
        ->and($this->child->fresh()->status)->toBe(Status::ToDo);
});

it('cascades to subtasks when the modal is confirmed', function () {
    $this->member->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);

    ($this->view)($this->parent)
        ->set('status', Status::Done->value)
        ->call('confirmCascade')
        ->assertSet('confirmingCascade', false);

    expect($this->parent->fresh()->status)->toBe(Status::Done)
        ->and($this->child->fresh()->status)->toBe(Status::Done);
});

it('changes only the parent when the modal is declined', function () {
    $this->member->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);

    ($this->view)($this->parent)
        ->set('status', Status::Done->value)
        ->call('declineCascade');

    expect($this->parent->fresh()->status)->toBe(Status::Done)
        ->and($this->child->fresh()->status)->toBe(Status::ToDo);
});

it('changes nothing and restores the control when the modal is aborted', function () {
    $this->member->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);

    ($this->view)($this->parent)
        ->set('status', Status::Done->value)
        ->call('abortCascade')
        ->assertSet('confirmingCascade', false)
        ->assertSet('status', Status::InProgress->value);

    expect($this->parent->fresh()->status)->toBe(Status::InProgress)
        ->and($this->child->fresh()->status)->toBe(Status::ToDo);
});

it('skips the modal and cascades directly under the "always" preference', function () {
    $this->member->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Always->value);

    ($this->view)($this->parent)
        ->set('status', Status::Done->value)
        ->assertSet('confirmingCascade', false);

    expect($this->parent->fresh()->status)->toBe(Status::Done)
        ->and($this->child->fresh()->status)->toBe(Status::Done);
});

it('skips the modal and leaves subtasks under the "never" preference', function () {
    $this->member->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Never->value);

    ($this->view)($this->parent)
        ->set('status', Status::Done->value)
        ->assertSet('confirmingCascade', false);

    expect($this->parent->fresh()->status)->toBe(Status::Done)
        ->and($this->child->fresh()->status)->toBe(Status::ToDo);
});

it('remembers the modal choice as the cascade preference', function () {
    $this->member->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);

    ($this->view)($this->parent)
        ->set('status', Status::Done->value)
        ->set('rememberCascadeChoice', true)
        ->call('confirmCascade');

    expect($this->member->fresh()->preference(ChangeTaskStatus::PREFERENCE_KEY))
        ->toBe(CascadePreference::Always->value);
});

it('silently bumps the parent when a child starts and offers an undo', function () {
    // Reset the parent so the bump is observable.
    $this->parent->status = Status::Planned;
    $this->parent->save();

    ($this->view)($this->child)
        ->set('status', Status::InProgress->value)
        ->assertSet('parentBumpUndoStatus', Status::Planned->value)
        ->assertSee(__('The parent task was moved to In progress.'));

    expect($this->parent->fresh()->status)->toBe(Status::InProgress);
});

it('undoes the silent parent bump', function () {
    $this->parent->status = Status::Planned;
    $this->parent->save();

    ($this->view)($this->child)
        ->set('status', Status::InProgress->value)
        ->call('undoParentBump')
        ->assertSet('parentBumpUndoStatus', '');

    expect($this->parent->fresh()->status)->toBe(Status::Planned)
        ->and($this->child->fresh()->status)->toBe(Status::InProgress);
});

describe('the close-the-parent prompt', function () {
    it('prompts to close the parent when the last open subtask is completed (ask)', function () {
        $this->member->setPreference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY, CascadePreference::Ask->value);

        ($this->view)($this->child)
            ->set('status', Status::Done->value)
            ->assertSet('confirmingParentClose', true)
            ->assertSet('parentCloseReference', $this->parent->reference);

        // Nothing happens to the parent until the prompt is resolved.
        expect($this->parent->fresh()->status)->toBe(Status::InProgress);
    });

    it('closes the parent when the prompt is confirmed', function () {
        ($this->view)($this->child)
            ->set('status', Status::Done->value)
            ->call('confirmParentClose')
            ->assertSet('confirmingParentClose', false);

        expect($this->parent->fresh()->status)->toBe(Status::Done)
            ->and($this->child->fresh()->status)->toBe(Status::Done);
    });

    it('leaves the parent open when the prompt is declined', function () {
        ($this->view)($this->child)
            ->set('status', Status::Done->value)
            ->call('declineParentClose')
            ->assertSet('confirmingParentClose', false);

        expect($this->parent->fresh()->status)->toBe(Status::InProgress);
    });

    it('remembers "always" when confirmed with the checkbox', function () {
        ($this->view)($this->child)
            ->set('status', Status::Done->value)
            ->set('rememberParentCloseChoice', true)
            ->call('confirmParentClose');

        expect($this->member->fresh()->preference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY))
            ->toBe(CascadePreference::Always->value);
    });

    it('remembers "never" when declined with the checkbox', function () {
        ($this->view)($this->child)
            ->set('status', Status::Done->value)
            ->set('rememberParentCloseChoice', true)
            ->call('declineParentClose');

        expect($this->member->fresh()->preference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY))
            ->toBe(CascadePreference::Never->value)
            ->and($this->parent->fresh()->status)->toBe(Status::InProgress);
    });

    it('auto-closes the parent without prompting under "always"', function () {
        $this->member->setPreference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY, CascadePreference::Always->value);

        ($this->view)($this->child)
            ->set('status', Status::Done->value)
            ->assertSet('confirmingParentClose', false);

        expect($this->parent->fresh()->status)->toBe(Status::Done);
    });

    it('does not prompt under "never"', function () {
        $this->member->setPreference(ChangeTaskStatus::PARENT_CLOSE_PREFERENCE_KEY, CascadePreference::Never->value);

        ($this->view)($this->child)
            ->set('status', Status::Done->value)
            ->assertSet('confirmingParentClose', false);

        expect($this->parent->fresh()->status)->toBe(Status::InProgress);
    });
});
