<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Livewire\Projects\ProjectShow;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['description' => 'Project blurb']);
    $this->project->members()->attach($this->user);
});

it('caps and scrolls the project description', function () {
    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertSeeHtml('max-h-96 overflow-y-auto');
});

it('shows the description and open root tasks', function () {
    Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Open task']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertOk()
        ->assertSee('Project blurb')
        ->assertSee('Open task');
});

it('separates open root tasks from completed ones', function () {
    $completed = Task::factory()->for($this->project)->status(Status::Done)->create();
    $open = Task::factory()->for($this->project)->status(Status::ToDo)->create();

    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name]);

    expect($component->instance()->openTasks()->pluck('id'))
        ->toContain($open->id)
        ->not->toContain($completed->id)
        ->and($component->instance()->completedTasks()->pluck('id'))
        ->toContain($completed->id);
});

it('treats a canceled root task as completed', function () {
    $canceled = Task::factory()->for($this->project)->status(Status::Canceled)->create();

    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name]);

    expect($component->instance()->openTasks()->pluck('id'))
        ->not->toContain($canceled->id)
        ->and($component->instance()->completedTasks()->pluck('id'))
        ->toContain($canceled->id);
});

it('shows a freshly created task on the overview', function () {
    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('taskTitle', 'Test task')
        ->call('createTask')
        ->assertSee('Test task');

    $titles = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->instance()->openTasks()->pluck('title');

    expect($titles)->toContain('Test task');
});

it('creates a task from the overview with the default priority', function () {
    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('taskTitle', 'Brand new task')
        ->call('createTask');

    $task = $this->project->tasks()->where('title', 'Brand new task')->first();

    // The overview shares the creation action with the board, so a task made here
    // gets the same default priority as one made on the board.
    expect($task)->not->toBeNull()
        ->and($task->parent_id)->toBeNull()
        ->and($task->priority)->toBe(Priority::default());
});

it('archives and restores a root task', function () {
    $task = Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Archivable']);

    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->call('archiveTask', $task->id);

    expect($task->fresh()->isArchived())->toBeTrue();

    $component->call('unarchiveTask', $task->id);

    expect($task->fresh()->isArchived())->toBeFalse();
});

it('renames the short name and redirects to the new url', function () {
    $this->project->update(['short_name' => 'OLD']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => 'OLD'])
        ->call('edit')
        ->set('short_name', 'new')
        ->call('save')
        ->assertRedirect(route('project.show', ['short_name' => 'NEW']));

    expect($this->project->fresh()->short_name)->toBe('NEW');
});

it('rejects a short name already taken by another project', function () {
    Project::factory()->create(['short_name' => 'TAKN']);
    $this->project->update(['short_name' => 'MINE']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => 'MINE'])
        ->call('edit')
        ->set('short_name', 'TAKN')
        ->call('save')
        ->assertHasErrors('short_name');

    expect($this->project->fresh()->short_name)->toBe('MINE');
});

it('forbids non-members', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertForbidden();
});
