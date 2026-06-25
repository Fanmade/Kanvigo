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

it('counts open subtasks from the eager-loaded subtree without a new recursive query', function () {
    // A two-level subtree: two open children and one done grandchild.
    $childA = Task::factory()->for($this->project)->childOf($this->task)->status(Status::ToDo)->create();
    Task::factory()->for($this->project)->childOf($this->task)->status(Status::ToDo)->create();
    Task::factory()->for($this->project)->childOf($childA)->status(Status::Done)->create();

    // Mounting eager-loads `descendants` via the task() computed.
    $component = ($this->mountTask)();

    // Reading the open-subtask count must reuse that loaded relation, not re-query it.
    DB::enableQueryLog();
    $count = $component->instance()->openSubtaskCount();
    $recursive = collect(DB::getQueryLog())
        ->filter(static fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'recursive'))
        ->count();
    DB::disableQueryLog();

    expect($count)->toBe(2);
    expect($recursive)->toBe(0);
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
