<?php

use App\Enums\Status;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
    $this->task = Task::factory()->for($this->project)->status(Status::Planned)->create();

    $this->mountTask = fn () => Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $this->task->task_number,
        ]);
});

it('caps and scrolls the task description', function () {
    $this->task->update(['description' => 'A task description.']);

    ($this->mountTask)()
        ->assertSeeHtml('max-h-96 overflow-y-auto');
});

it('offers a shortcut from the task to the project board', function () {
    ($this->mountTask)()
        ->assertSeeHtml('data-test="task-board-link"')
        ->assertSeeHtml(route('project.board', $this->project));
});

it('changes the task status inline and logs the transition', function () {
    ($this->mountTask)()
        ->set('status', Status::Done->value);

    expect($this->task->fresh()->status)->toBe(Status::Done);

    assertDatabaseHas('activities', [
        'subject_type' => $this->task->getMorphClass(),
        'subject_id' => $this->task->id,
        'action' => 'status_changed',
        'old_value' => Status::Planned->value,
        'new_value' => Status::Done->value,
    ]);
});

it('ignores an invalid status without recording an activity', function () {
    ($this->mountTask)()
        ->set('status', 'NotAStatus');

    expect($this->task->fresh()->status)->toBe(Status::Planned)
        ->and($this->task->activities()->where('action', 'status_changed')->count())->toBe(0);
});

it('assigns the current user with one click and auto-subscribes them', function () {
    ($this->mountTask)()
        ->assertSet('assigneeIds', [])
        ->call('assignToMe')
        ->assertSet('assigneeIds', [$this->member->id]);

    expect($this->task->fresh()->assignees->pluck('id')->all())->toBe([$this->member->id])
        ->and($this->task->isSubscribedBy($this->member))->toBeTrue();

    assertDatabaseHas('activities', [
        'subject_type' => $this->task->getMorphClass(),
        'subject_id' => $this->task->id,
        'action' => 'assignee_changed',
    ]);
});

it('keeps a single assignment when assign-to-me is clicked twice', function () {
    $component = ($this->mountTask)();

    $component->call('assignToMe')->call('assignToMe')
        ->assertSet('assigneeIds', [$this->member->id]);

    expect($this->task->fresh()->assignees)->toHaveCount(1);
});

it('refuses to assign a user who is not a project member', function () {
    $outsider = User::factory()->create();

    ($this->mountTask)()
        ->set('assigneeIds', [$outsider->id])
        ->assertSet('assigneeIds', []);

    expect($this->task->fresh()->assignees)->toHaveCount(0)
        ->and($this->task->isSubscribedBy($outsider))->toBeFalse();
});

it('clamps a tampered assignee list to project members only', function () {
    $outsider = User::factory()->create();

    ($this->mountTask)()
        ->set('assigneeIds', [$this->member->id, $outsider->id])
        ->assertSet('assigneeIds', [$this->member->id]);

    expect($this->task->fresh()->assignees->pluck('id')->all())->toBe([$this->member->id])
        ->and($this->task->isSubscribedBy($outsider))->toBeFalse();
});

it('enters edit mode populating the form, then saves and exits', function () {
    $this->task->update(['title' => 'Old title']);

    ($this->mountTask)()
        ->call('edit')
        ->assertSet('editing', true)
        ->assertSet('title', 'Old title')
        ->set('title', 'New title')
        ->call('save')
        ->assertSet('editing', false);

    expect($this->task->fresh()->title)->toBe('New title');
});

it('renders the task page with a query count that does not grow with subtree size', function () {
    // Number of queries to render the task page for a task with $subtasks children.
    $queriesToRender = function (int $subtasks): int {
        $project = Project::factory()->create();
        joinProject($project, $this->member);
        $task = Task::factory()->for($project)->create();
        Task::factory()->count($subtasks)->for($project)->childOf($task)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->member)
            ->test(TaskView::class, ['short_name' => $project->short_name, 'task_number' => $task->task_number])
            ->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // A page with 20 subtasks must issue no more queries than one with 2 — the
    // subtree is loaded in bulk, so adding subtasks must not add queries (no N+1).
    expect($queriesToRender(20))->toBeLessThanOrEqual($queriesToRender(2));
});

it('defers the activity feed off the task page initial render', function () {
    // Creating the task already recorded a "created" activity, so a non-lazy feed
    // would query the activities table while rendering the page.
    DB::enableQueryLog();
    $html = ($this->mountTask)()->html();
    $activityQueries = collect(DB::getQueryLog())
        ->filter(static fn (array $entry): bool => str_contains((string) $entry['query'], 'from "activities"'))
        ->count();
    DB::disableQueryLog();

    // The feed is lazy: the page renders its placeholder and touches no activity
    // until it scrolls into view.
    expect($activityQueries)->toBe(0);
    expect($html)->toContain('activity-placeholder');
});

it('rejects an empty task title', function () {
    ($this->mountTask)()
        ->call('edit')
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});

it('rejects a task title longer than 255 characters', function () {
    ($this->mountTask)()
        ->call('edit')
        ->set('title', str_repeat('a', 256))
        ->call('save')
        ->assertHasErrors(['title' => 'max']);
});
