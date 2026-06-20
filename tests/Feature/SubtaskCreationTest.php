<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->member);
    $this->story = Story::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->story)->priority(Priority::High)->create();

    $this->view = fn (Task $task) => Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number]);
});

it('creates a subtask nested under the task, inheriting the parent priority', function () {
    ($this->view)($this->task)
        ->call('openSubtaskModal')
        ->assertSet('subtaskPriority', Priority::High->value)
        ->set('subtaskTitle', 'A subtask')
        ->call('createSubtask')
        ->assertSet('showSubtaskModal', false);

    $child = $this->task->children()->first();

    expect($child)->not->toBeNull()
        ->and($child->title)->toBe('A subtask')
        ->and($child->parent_id)->toBe($this->task->id)
        ->and($child->story_id)->toBe($this->story->id);
});

it('requires a subtask title', function () {
    ($this->view)($this->task)
        ->call('openSubtaskModal')
        ->set('subtaskTitle', '')
        ->call('createSubtask')
        ->assertHasErrors(['subtaskTitle' => 'required']);
});

it('forbids a non-member from opening the task view', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
        ->assertForbidden();
});

it('cannot add a subtask at the maximum nesting depth', function () {
    config(['kanbrio.tasks.max_depth' => 2]);
    $child = Task::factory()->for($this->story)->childOf($this->task)->create(); // depth 2 = max

    $component = ($this->view)($child);
    expect($component->instance()->canAddSubtask)->toBeFalse();

    // The new-subtask button is hidden, and a forced call is refused.
    $component->assertDontSeeHtml('data-test="new-subtask"')
        ->set('subtaskTitle', 'Too deep')
        ->call('createSubtask');

    expect($child->children()->count())->toBe(0);
});

it('rolls up progress from the whole descendant subtree', function () {
    $child = Task::factory()->for($this->story)->childOf($this->task)->status(Status::Done)->create();
    Task::factory()->for($this->story)->childOf($child)->status(Status::ToDo)->create();
    Task::factory()->for($this->story)->childOf($this->task)->status(Status::ToDo)->create();

    $progress = $this->task->fresh()->progress();

    expect($progress->done)->toBe(1)   // only the Done descendant
        ->and($progress->total)->toBe(3); // all three descendants
});

it('shows ancestors, the rolled-up progress and the direct children on the detail page', function () {
    $child = Task::factory()->for($this->story)->childOf($this->task)->status(Status::Done)->create(['title' => 'Child A']);
    $grandchild = Task::factory()->for($this->story)->childOf($child)->create(['title' => 'Grandchild']);

    // On the middle task's page: a breadcrumb link up to the root, its own child listed,
    // and a 0/1 rollup for its subtree.
    ($this->view)($child)
        ->assertSeeHtml('data-test="ancestor-'.$this->task->id.'"')
        ->assertSeeHtml('data-test="subtask-'.$grandchild->id.'"')
        ->assertSee('Grandchild')
        ->assertSee('0 / 1');
});
