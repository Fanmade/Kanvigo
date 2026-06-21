<?php

use App\Enums\Priority;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->member);
});

it('creates a task with a chosen priority from the board', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('openTaskModal')
        ->set('taskTitle', 'Launch')
        ->set('taskPriority', Priority::High->value)
        ->call('createTask');

    expect($this->project->tasks()->where('title', 'Launch')->first()->priority)
        ->toBe(Priority::High);
});

it('defaults the new-task priority to the project default when the modal opens', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('openTaskModal')
        ->assertSet('taskPriority', Priority::default()->value)
        ->set('taskTitle', 'Ship it')
        ->call('createTask');

    expect($this->project->tasks()->where('title', 'Ship it')->first()->priority)
        ->toBe(Priority::default());
});

it('updates a task priority inline from the task view and logs it', function () {
    $task = Task::factory()->for($this->project)->priority(Priority::Medium)->create();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $task->task_number,
        ])
        ->set('priority', Priority::Highest->value);

    expect($task->fresh()->priority)->toBe(Priority::Highest);
    assertDatabaseHas('activities', [
        'subject_id' => $task->id,
        'action' => 'priority_changed',
        'field' => 'priority',
        'new_value' => (string) Priority::Highest->value,
    ]);
});

it('defaults a new subtask priority to the parent task and creates with it', function () {
    $parent = Task::factory()->for($this->project)->priority(Priority::Highest)->create();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $parent->task_number,
        ])
        ->call('openSubtaskModal')
        ->assertSet('subtaskPriority', Priority::Highest->value)
        ->set('subtaskTitle', 'Nested work')
        ->call('createSubtask');

    expect($parent->children()->where('title', 'Nested work')->first()->priority)
        ->toBe(Priority::Highest);
});
