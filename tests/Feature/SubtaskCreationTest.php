<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Livewire\Tasks\CreateTaskModal;
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
    $this->task = Task::factory()->for($this->project)->priority(Priority::High)->create();

    $this->view = fn (Task $task) => Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number]);
});

it('creates a subtask nested under the task, inheriting the parent priority', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id, $this->task->id)
        ->assertSet('priority', Priority::High->value)
        ->set('title', 'A subtask')
        ->call('save')
        ->assertSet('show', false);

    $child = $this->task->children()->first();

    expect($child)->not->toBeNull()
        ->and($child->title)->toBe('A subtask')
        ->and($child->parent_id)->toBe($this->task->id)
        ->and($child->project_id)->toBe($this->project->id);
});

it('requires a subtask title', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id, $this->task->id)
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});

it('forbids a non-member from opening the task view', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->task->task_number])
        ->assertForbidden();
});

it('cannot add a subtask at the maximum nesting depth', function () {
    config(['kanvigo.tasks.max_depth' => 2]);
    $child = Task::factory()->for($this->project)->childOf($this->task)->create(); // depth 2 = max

    $component = ($this->view)($child);
    expect($component->instance()->canAddSubtask)->toBeFalse();

    // With nothing to add and no children, the whole subtasks section is hidden —
    // no button and no empty "No subtasks yet." placeholder.
    $component->assertDontSeeHtml('data-test="new-subtask"')
        ->assertDontSee(__('No subtasks yet.'));
});

it('rolls up progress from the whole descendant subtree', function () {
    $child = Task::factory()->for($this->project)->childOf($this->task)->status(Status::Done)->create();
    Task::factory()->for($this->project)->childOf($child)->status(Status::ToDo)->create();
    Task::factory()->for($this->project)->childOf($this->task)->status(Status::ToDo)->create();

    $progress = $this->task->fresh()->progress();

    expect($progress->done)->toBe(1)   // only the Done descendant
        ->and($progress->total)->toBe(3); // all three descendants
});

it('shows ancestors, the rolled-up progress and the direct children on the detail page', function () {
    $child = Task::factory()->for($this->project)->childOf($this->task)->status(Status::Done)->create(['title' => 'Child A']);
    $grandchild = Task::factory()->for($this->project)->childOf($child)->status(Status::ToDo)->create(['title' => 'Grandchild']);

    // On the middle task's page: a breadcrumb link up to the root, its own child listed,
    // and a 0/1 rollup for its subtree.
    ($this->view)($child)
        ->assertSeeHtml('data-test="ancestor-'.$this->task->id.'"')
        ->assertSeeHtml('data-test="subtask-'.$grandchild->id.'"')
        ->assertSee('Grandchild')
        ->assertSee('0 / 1');
});
