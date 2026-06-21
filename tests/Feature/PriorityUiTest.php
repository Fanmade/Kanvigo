<?php

use App\Enums\Priority;
use App\Livewire\Tasks\CreateTaskModal;
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

it('creates a task with a chosen priority from the create dialog', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Launch')
        ->set('priority', Priority::High->value)
        ->call('save');

    expect($this->project->tasks()->where('title', 'Launch')->first()->priority)
        ->toBe(Priority::High);
});

it('defaults the new-task priority to the project default when the dialog opens', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->assertSet('priority', Priority::default()->value)
        ->set('title', 'Ship it')
        ->call('save');

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
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id, $parent->id)
        ->assertSet('priority', Priority::Highest->value)
        ->set('title', 'Nested work')
        ->call('save');

    expect($parent->children()->where('title', 'Nested work')->first()->priority)
        ->toBe(Priority::Highest);
});
