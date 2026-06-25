<?php

use App\Enums\Priority;
use App\Enums\Status;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Tasks\CreateTaskModal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        ->set('tasksCollapsed', false)
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
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('tasksCollapsed', false);

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
        ->set('tasksCollapsed', false)
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
        ->set('tasksCollapsed', false)
        ->assertSee('Direct child')
        ->assertDontSee('Deep grandchild');
});

it('hides archived subtasks until the show-archived toggle is on', function () {
    $root = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $archivedChild = Task::factory()->for($this->project)->childOf($root)->create(['title' => 'Archived child']);
    $archivedChild->archive();

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('tasksCollapsed', false)
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

it('collapses the task list by default', function () {
    Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Hidden task']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertSet('tasksCollapsed', true)
        ->assertSeeHtml('data-test="toggle-tasks"')
        ->assertDontSeeHtml('data-test="project-tasks-body"')
        ->assertDontSee('Hidden task');
});

it('expands the task list and persists the preference when toggled', function () {
    Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Revealed task']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->call('toggleTasksCollapsed')
        ->assertSet('tasksCollapsed', false)
        ->assertSeeHtml('data-test="project-tasks-body"')
        ->assertSee('Revealed task');

    expect($this->user->fresh()->preference('project_tasks_collapsed'))->toBeFalse();
});

it('reflects the saved expanded preference on mount', function () {
    $this->user->setPreference('project_tasks_collapsed', false);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertSet('tasksCollapsed', false)
        ->assertSeeHtml('data-test="project-tasks-body"');
});

it('hides closed tasks by default and reveals them via the filter', function () {
    Task::factory()->for($this->project)->status(Status::Done)->create(['title' => 'Done thing']);

    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('tasksCollapsed', false)
        ->assertDontSeeHtml('data-test="closed-tasks"')
        ->assertDontSee('Done thing')
        ->set('showClosed', true)
        ->assertSeeHtml('data-test="closed-tasks"')
        ->assertSee('Done thing');
});

it('filters the task list by one or several priorities', function () {
    $high = Task::factory()->for($this->project)->status(Status::ToDo)->priority(Priority::High)->create();
    $low = Task::factory()->for($this->project)->status(Status::ToDo)->priority(Priority::Low)->create();
    $medium = Task::factory()->for($this->project)->status(Status::ToDo)->priority(Priority::Medium)->create();

    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name]);

    // A single priority.
    expect($component->set('priorityFilters', [Priority::High->value])->instance()->openTasks()->pluck('id'))
        ->toContain($high->id)->not->toContain($low->id)->not->toContain($medium->id);

    // Several priorities at once (any of).
    expect($component->set('priorityFilters', [Priority::High->value, Priority::Low->value])->instance()->openTasks()->pluck('id'))
        ->toContain($high->id)->toContain($low->id)->not->toContain($medium->id);
});

it('filters the task list by tags, matching any or all', function () {
    $bug = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $bug->syncTags('Bug');
    $both = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $both->syncTags('Bug,UI/UX');
    Task::factory()->for($this->project)->status(Status::ToDo)->create(); // untagged

    $bugId = $this->project->tags()->where('name', 'Bug')->value('id');
    $uiId = $this->project->tags()->where('name', 'UI/UX')->value('id');

    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('tagFilters', [$bugId, $uiId]);

    // "Any" keeps tasks carrying at least one of the tags.
    expect($component->set('tagMatch', 'any')->instance()->openTasks()->pluck('id'))
        ->toContain($bug->id)->toContain($both->id)->toHaveCount(2);

    // "All" keeps only tasks carrying every selected tag.
    expect($component->set('tagMatch', 'all')->instance()->openTasks()->pluck('id'))
        ->toContain($both->id)->not->toContain($bug->id)->toHaveCount(1);
});

it('filters the task list by assignees, matching any or all', function () {
    [$alice, $bob] = User::factory()->count(2)->create();

    $aliceOnly = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $aliceOnly->assignees()->attach($alice->id);
    $both = Task::factory()->for($this->project)->status(Status::ToDo)->create();
    $both->assignees()->attach([$alice->id, $bob->id]);
    Task::factory()->for($this->project)->status(Status::ToDo)->create(); // unassigned

    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('assigneeFilters', [$alice->id, $bob->id]);

    // "Any" keeps tasks assigned to at least one of the people.
    expect($component->set('assigneeMatch', 'any')->instance()->openTasks()->pluck('id'))
        ->toContain($aliceOnly->id)->toContain($both->id)->toHaveCount(2);

    // "All" keeps only tasks assigned to everyone selected.
    expect($component->set('assigneeMatch', 'all')->instance()->openTasks()->pluck('id'))
        ->toContain($both->id)->not->toContain($aliceOnly->id)->toHaveCount(1);
});

it('counts active task-list filters for the badge', function () {
    $component = Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name]);

    expect($component->instance()->activeTaskFilterCount())->toBe(0);

    $component->set('priorityFilters', [Priority::High->value]);
    expect($component->instance()->activeTaskFilterCount())->toBe(1);

    $component->set('showClosed', true);
    expect($component->instance()->activeTaskFilterCount())->toBe(2);
});

it('renders the task filters control when expanded', function () {
    Livewire::actingAs($this->user)
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->set('tasksCollapsed', false)
        ->assertSeeHtml('data-test="task-filters"')
        ->assertSeeHtml('data-test="priority-filter"');
});

it('forbids non-members', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ProjectShow::class, ['short_name' => $this->project->short_name])
        ->assertForbidden();
});

it('serves the overview root tasks from cache on an idle re-render', function () {
    Task::factory()->count(3)->for($this->project)->create();

    // First render builds and caches the root-task load under the board token.
    Livewire::actingAs($this->user)->test(ProjectShow::class, ['short_name' => $this->project->short_name]);

    DB::enableQueryLog();
    Livewire::actingAs($this->user)->test(ProjectShow::class, ['short_name' => $this->project->short_name]);
    $taskQueries = collect(DB::getQueryLog())
        ->filter(static fn (array $entry): bool => str_contains((string) $entry['query'], 'from "tasks"'))
        ->count();
    DB::disableQueryLog();

    // No write happened, so the overview is served from cache — no task scan.
    expect($taskQueries)->toBe(0);
});

it('rebuilds the overview after a task change invalidates the cache', function () {
    Task::factory()->count(3)->for($this->project)->create();

    $component = Livewire::actingAs($this->user)->test(ProjectShow::class, ['short_name' => $this->project->short_name]);
    expect($component->instance()->rootTasks())->toHaveCount(3);

    // A new root task bumps the project's board version via the Task saved hook.
    Task::factory()->for($this->project)->create();

    // A fresh render reflects the change rather than the stale cached set.
    $component = Livewire::actingAs($this->user)->test(ProjectShow::class, ['short_name' => $this->project->short_name]);
    expect($component->instance()->rootTasks())->toHaveCount(4);
});

it('renders the overview with a query count that does not grow with subtree size', function () {
    // Each render uses a fresh project, so neither benefits from the other's cache.
    $queriesToRender = function (int $subtasks): int {
        $project = Project::factory()->withOwner($this->user)->create();
        $root = Task::factory()->for($project)->create();
        Task::factory()->count($subtasks)->for($project)->childOf($root)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)
            ->test(ProjectShow::class, ['short_name' => $project->short_name])
            ->set('tasksCollapsed', false);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // A root task with 20 subtasks must issue no more queries than one with 2 —
    // the subtree loads in bulk, so adding subtasks must not add queries (no N+1).
    expect($queriesToRender(20))->toBeLessThanOrEqual($queriesToRender(2));
});

it('renders the overview with a query count that does not grow with the number of root tasks', function () {
    $queriesForRoots = function (int $roots): int {
        $project = Project::factory()->withOwner($this->user)->create();
        foreach (range(1, $roots) as $ignored) {
            $root = Task::factory()->for($project)->create();
            Task::factory()->count(2)->for($project)->childOf($root)->create();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)
            ->test(ProjectShow::class, ['short_name' => $project->short_name])
            ->set('tasksCollapsed', false);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // Each root card rolls up its own progress() from the eager-loaded subtree, so
    // adding root tasks must not add queries (no per-card progress() N+1).
    expect($queriesForRoots(8))->toBeLessThanOrEqual($queriesForRoots(2));
});
