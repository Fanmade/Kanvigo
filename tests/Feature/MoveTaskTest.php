<?php

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

    $this->view = fn (Task $task) => Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number]);
});

it('moves a root task under a new parent', function () {
    $task = Task::factory()->for($this->project)->create();
    $newParent = Task::factory()->for($this->project)->status(Status::ToDo)->create();

    ($this->view)($task)
        ->call('startMoveParent')
        ->set('newParentId', $newParent->id)
        ->call('moveParent')
        ->assertSet('movingParent', false)
        ->assertHasNoErrors();

    expect($task->fresh()->parent_id)->toBe($newParent->id);
});

it('detaches a subtask to the top level', function () {
    $parent = Task::factory()->for($this->project)->create();
    $child = Task::factory()->for($this->project)->childOf($parent)->create();

    ($this->view)($child)
        ->set('newParentId', null)
        ->call('moveParent')
        ->assertHasNoErrors();

    expect($child->fresh()->parent_id)->toBeNull();
});

it('rejects moving a task under its own descendant', function () {
    $parent = Task::factory()->for($this->project)->create();
    $child = Task::factory()->for($this->project)->childOf($parent)->create();

    ($this->view)($parent)
        ->set('newParentId', $child->id)
        ->call('moveParent')
        ->assertHasErrors('newParentId');

    expect($parent->fresh()->parent_id)->toBeNull();
});

it('rejects a target that would push the subtree past the depth limit', function () {
    // max_depth is 3: A(1) → B(2) → C(3).
    $a = Task::factory()->for($this->project)->create();
    $b = Task::factory()->for($this->project)->childOf($a)->create();
    $c = Task::factory()->for($this->project)->childOf($b)->create();
    $task = Task::factory()->for($this->project)->create();

    $component = ($this->view)($task);

    expect(array_keys($component->instance()->parentMoveOptions()))->not->toContain($c->id);

    $component->set('newParentId', $c->id)
        ->call('moveParent')
        ->assertHasErrors('newParentId');

    expect($task->fresh()->parent_id)->toBeNull();
});

it('excludes itself, its descendants, and archived or terminal tasks from the options', function () {
    $task = Task::factory()->for($this->project)->create();
    $descendant = Task::factory()->for($this->project)->childOf($task)->create();
    $archived = Task::factory()->for($this->project)->archived()->create();
    $done = Task::factory()->for($this->project)->status(Status::Done)->create();
    $eligible = Task::factory()->for($this->project)->status(Status::ToDo)->create();

    $options = ($this->view)($task)->instance()->parentMoveOptions();
    $ids = array_keys($options);

    expect($ids)->toContain($eligible->id)
        ->and($ids)->not->toContain($task->id)
        ->and($ids)->not->toContain($descendant->id)
        ->and($ids)->not->toContain($archived->id)
        ->and($ids)->not->toContain($done->id);
});

it('records a parent_changed activity describing the move', function () {
    $task = Task::factory()->for($this->project)->create();
    $newParent = Task::factory()->for($this->project)->status(Status::ToDo)->create();

    ($this->view)($task)
        ->set('newParentId', $newParent->id)
        ->call('moveParent');

    $activity = $task->activities()->where('action', 'parent_changed')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->old_value)->toBeNull()
        ->and($activity->new_value)->toBe($newParent->reference);
});

it('does not let a non-member move the task', function () {
    $task = Task::factory()->for($this->project)->create();
    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
        ->assertForbidden();
});
