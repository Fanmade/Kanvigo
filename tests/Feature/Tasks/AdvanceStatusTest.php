<?php

use App\Actions\ChangeTaskStatus;
use App\Enums\CascadePreference;
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

it('advances a task to the next status in one click', function () {
    $task = Task::factory()->for($this->project)->status(Status::Planned)->create();

    ($this->view)($task)->call('advanceStatus');

    expect($task->fresh()->status)->toBe(Status::ToDo);
});

it('advances from In progress straight to Done', function () {
    $task = Task::factory()->for($this->project)->status(Status::InProgress)->create();

    ($this->view)($task)->call('advanceStatus');

    expect($task->fresh()->status)->toBe(Status::Done);
});

it('offers no next status once a task is Done', function () {
    $task = Task::factory()->for($this->project)->status(Status::Done)->create();

    expect(($this->view)($task)->instance()->nextStatus())->toBeNull();
});

it('hides the next-status step from a viewer who cannot change status', function () {
    $viewer = userWithPermissions($this->project, []); // view-project only
    $task = Task::factory()->for($this->project)->status(Status::Planned)->create();

    $component = Livewire::actingAs($viewer)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $task->task_number]);

    expect($component->instance()->nextStatus())->toBeNull();

    // The guard makes advancing a no-op even if the action is invoked directly.
    $component->call('advanceStatus');
    expect($task->fresh()->status)->toBe(Status::Planned);
});

it('routes a one-click advance to Done through the cascade prompt when subtasks are open', function () {
    $this->member->setPreference(ChangeTaskStatus::PREFERENCE_KEY, CascadePreference::Ask->value);

    $parent = Task::factory()->for($this->project)->status(Status::InProgress)->create();
    Task::factory()->for($this->project)->childOf($parent)->status(Status::ToDo)->create();

    ($this->view)($parent)
        ->call('advanceStatus')
        ->assertSet('confirmingCascade', true);

    // Held until the prompt is resolved.
    expect($parent->fresh()->status)->toBe(Status::InProgress);
});
