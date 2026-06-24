<?php

use App\Livewire\Activity\ActivityFeed;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
});

it('sets a type on an untyped task from the task view and logs it', function () {
    $task = Task::factory()->for($this->project)->create();
    $type = TaskType::factory()->for($this->project)->create(['name' => 'Bug']);

    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
        ->set('typeId', $type->id);

    expect($task->fresh()->task_type_id)->toBe($type->id);
    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'type_changed',
        'field' => 'type',
        'old_value' => null,
        'new_value' => 'Bug',
    ]);
});

it('changes a task from one type to another', function () {
    $bug = TaskType::factory()->for($this->project)->create(['name' => 'Bug']);
    $feature = TaskType::factory()->for($this->project)->create(['name' => 'Feature']);
    $task = Task::factory()->for($this->project)->create();
    $task->taskType()->associate($bug)->save();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
        ->set('typeId', $feature->id);

    expect($task->fresh()->task_type_id)->toBe($feature->id);
    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'type_changed',
        'old_value' => 'Bug',
        'new_value' => 'Feature',
    ]);
});

it('clears the type back to untyped', function () {
    $type = TaskType::factory()->for($this->project)->create(['name' => 'Bug']);
    $task = Task::factory()->for($this->project)->create();
    $task->taskType()->associate($type)->save();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
        ->set('typeId', null);

    expect($task->fresh()->task_type_id)->toBeNull();
    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'type_changed',
        'old_value' => 'Bug',
        'new_value' => null,
    ]);
});

it('rejects a type belonging to a different project', function () {
    $foreignType = TaskType::factory()->for(Project::factory())->create();
    $task = Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
        ->set('typeId', $foreignType->id)
        ->assertSet('typeId', null);

    expect($task->fresh()->task_type_id)->toBeNull();
    assertDatabaseMissing('activities', [
        'subject_id' => $task->id,
        'action' => 'type_changed',
    ]);
});

it('describes the type change in the activity feed', function () {
    $type = TaskType::factory()->for($this->project)->create(['name' => 'Bug']);
    $task = Task::factory()->for($this->project)->create();

    // Record the change through the task view, then read the expanded feed.
    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
        ->set('typeId', $type->id);

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $task->fresh()])
        ->call('toggleCollapsed')
        ->assertSee('set the type to Bug');
});

it('hides the type control when the project has no types', function () {
    $task = Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number])
        ->assertDontSeeHtml('data-test="task-type-control"');
});
