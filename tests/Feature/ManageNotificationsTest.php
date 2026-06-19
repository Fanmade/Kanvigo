<?php

use App\Enums\Status;
use App\Livewire\Notifications\ManageNotifications;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Story;
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
    $this->story = Story::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->story)->status(Status::Planned)->create();

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

it('includes subscribed stories as their own group', function () {
    $this->story->subscribe($this->user);

    $rows = Livewire::actingAs($this->user)
        ->test(ManageNotifications::class)
        ->instance()
        ->rows();

    $story = collect($rows)->firstWhere('type', 'story');

    expect($story)->not->toBeNull()
        ->and($story['group'])->toBe('Stories')
        ->and($story['id'])->toBe($this->story->id)
        ->and($story['label'])->toBe($this->story->reference.' · '.$this->story->title)
        ->and($story['total'])->toBe(0)
        ->and($story['unread'])->toBe(0);
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
    $otherTask = Task::factory()->for(Story::factory()->for($otherProject))->create();
    $otherTask->subscribe($stranger);

    // A tampered id the caller has no subscription to is scoped out — a no-op
    // that cannot remove another user's subscription pivot.
    Livewire::actingAs($this->user)
        ->test(ManageNotifications::class)
        ->call('unsubscribe', 'task', $otherTask->id);

    expect($otherTask->fresh()->isSubscribedBy($stranger))->toBeTrue()
        ->and($this->task->fresh()->isSubscribedBy($this->user))->toBeTrue();
});
