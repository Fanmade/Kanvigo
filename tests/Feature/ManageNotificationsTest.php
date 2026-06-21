<?php

use App\Enums\Status;
use App\Livewire\Notifications\ManageNotifications;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actor = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach([$this->user->id, $this->actor->id]);
    $this->task = Task::factory()->for($this->project)->status(Status::Planned)->create();

    $this->project->subscribe($this->user);
    $this->task->subscribe($this->user);
});

it('lists subscriptions grouped with notification counts', function () {
    // Generate a notification on the task for the subscriber.
    Livewire::actingAs($this->actor)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('moveTask', $this->task->id, Status::Done->value);

    $rows = Livewire::actingAs($this->user)
        ->test(ManageNotifications::class)
        ->instance()
        ->rows();

    $project = collect($rows)->firstWhere('type', 'project');
    $task = collect($rows)->firstWhere('type', 'task');

    expect($project)->not->toBeNull()
        ->and($project['unread'])->toBe(0)
        ->and($task)->not->toBeNull()
        ->and($task['total'])->toBe(1)
        ->and($task['unread'])->toBe(1);
});

it('unsubscribes from an item on the spot', function () {
    expect($this->task->isSubscribedBy($this->user))->toBeTrue();

    Livewire::actingAs($this->user)
        ->test(ManageNotifications::class)
        ->call('unsubscribe', 'task', $this->task->id);

    expect($this->task->fresh()->isSubscribedBy($this->user))->toBeFalse()
        ->and($this->project->fresh()->isSubscribedBy($this->user))->toBeTrue();
});

it('ignores an unsubscribe for an item the user is not subscribed to', function () {
    $stranger = User::factory()->create();
    $otherProject = Project::factory()->create();
    $otherProject->members()->attach($stranger);
    $otherTask = Task::factory()->for($otherProject)->create();
    $otherTask->subscribe($stranger);

    // A tampered id the caller has no subscription to is scoped out — a no-op
    // that cannot remove another user's subscription pivot.
    Livewire::actingAs($this->user)
        ->test(ManageNotifications::class)
        ->call('unsubscribe', 'task', $otherTask->id);

    expect($otherTask->fresh()->isSubscribedBy($stranger))->toBeTrue()
        ->and($this->task->fresh()->isSubscribedBy($this->user))->toBeTrue();
});
