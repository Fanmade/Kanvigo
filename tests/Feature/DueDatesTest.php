<?php

use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Tasks\CreateTaskModal;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->create();
});

it('casts the due date to a date instance', function () {
    $task = Task::factory()->dueOn('2026-07-01')->create();

    expect($task->fresh()->due_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-07-01');
});

it('saves a due date through the task view', function () {
    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $this->task->task_number,
        ])
        ->call('edit')
        ->set('dueDate', '2026-08-15')
        ->call('save');

    expect($this->task->fresh()->due_date->format('Y-m-d'))->toBe('2026-08-15');
});

it('clears a due date through the task view', function () {
    $this->task->update(['due_date' => '2026-08-15']);

    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $this->task->task_number,
        ])
        ->call('edit')
        ->set('dueDate', '')
        ->call('save');

    expect($this->task->fresh()->due_date)->toBeNull();
});

it('rejects an invalid due date in the task view', function () {
    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $this->task->task_number,
        ])
        ->call('edit')
        ->set('dueDate', 'not-a-date')
        ->call('save')
        ->assertHasErrors(['dueDate' => 'date']);
});

it('creates a task with a due date from the create dialog', function () {
    Livewire::actingAs($this->member)
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Ship it')
        ->set('dueDate', '2026-09-02')
        ->call('save');

    expect($this->project->tasks()->where('title', 'Ship it')->first()->due_date->format('Y-m-d'))
        ->toBe('2026-09-02');
});

it('shows an overdue task due date on the board', function () {
    Task::factory()->for($this->project)->dueOn('2020-01-01')->create(['title' => 'Overdue task']);

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->assertSee('Jan 1, 2020');
});
