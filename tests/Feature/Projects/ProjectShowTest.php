<?php

use App\Enums\Status;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Tasks\CreateTaskModal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    // The sole member is the project's owner (its creator), so settings-edit
    // tests in this file act with the privilege real owners have.
    $this->project = Project::factory()->withOwner($this->user)->create(['description' => 'Project blurb']);
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
        ->test(CreateTaskModal::class)
        ->call('open', $this->project->id)
        ->set('title', 'Test task')
        ->call('save');

    $titles = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->instance()->openTasks()->pluck('title');

    expect($titles)->toContain('Test task');
});

it('refreshes the overview when a task is created through the dialog', function () {
    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name]);

    Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Late arrival']);

    $component->call('refreshAfterCreate')
        ->assertSee('Late arrival');
});

it('lists a root task\'s direct subtasks with links to their detail pages', function () {
    $root = Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Root task']);
    $child = Task::factory()->for($this->project)->childOf($root)->create(['title' => 'Child task']);

    $childUrl = route('task.show', [
        'short_name' => $this->project->short_name,
        'task_number' => $child->task_number,
    ]);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertSee('Child task')
        ->assertSeeHtml('href="'.$childUrl.'"')
        ->assertSeeHtml('data-test="root-task-subtask-'.$child->id.'"');
});

it('shows only direct children under a root card, not deeper descendants', function () {
    $root = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $child = Task::factory()->for($this->project)->childOf($root)->create(['title' => 'Direct child']);
    Task::factory()->for($this->project)->childOf($child)->create(['title' => 'Deep grandchild']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertSee('Direct child')
        ->assertDontSee('Deep grandchild');
});

it('hides archived subtasks until the show-archived toggle is on', function () {
    $root = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $archivedChild = Task::factory()->for($this->project)->childOf($root)->create(['title' => 'Archived child']);
    $archivedChild->archive();

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertDontSee('Archived child')
        ->set('showArchived', true)
        ->assertSee('Archived child');
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
