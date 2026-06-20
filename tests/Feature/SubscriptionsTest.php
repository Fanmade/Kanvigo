<?php

use App\Enums\Status;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Subscriptions\SubscriptionToggle;
use App\Livewire\Tasks\TaskView;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ItemActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->member);
    $this->story = Story::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->story)->status(Status::Planned)->create();
});

it('toggles a subscription on and off via the bell', function () {
    $component = Livewire::actingAs($this->member)
        ->test(SubscriptionToggle::class, ['subscribable' => $this->project]);

    expect($component->instance()->subscribed())->toBeFalse();

    $component->call('toggle');
    expect($this->project->isSubscribedBy($this->member))->toBeTrue();

    $component->call('toggle');
    expect($this->project->fresh()->isSubscribedBy($this->member))->toBeFalse();
});

it('auto-subscribes a user when they are assigned to a task', function () {
    Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $this->task->task_number,
        ])
        ->set('assigneeIds', [$this->member->id]);

    expect($this->task->isSubscribedBy($this->member))->toBeTrue();
});

it('forbids non-members from subscribing', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(SubscriptionToggle::class, ['subscribable' => $this->project])
        ->assertForbidden();
});

it('notifies subscribers but not the actor when an item is updated', function () {
    Notification::fake();

    $watcher = User::factory()->create();
    $this->project->members()->attach($watcher);
    $this->task->subscribe($watcher);
    $this->task->subscribe($this->member);

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('moveTask', $this->task->id, Status::Done->value);

    Notification::assertSentTo($watcher, ItemActivity::class);
    Notification::assertNotSentTo($this->member, ItemActivity::class);
});

it('notifies project subscribers about task updates', function () {
    Notification::fake();

    $watcher = User::factory()->create();
    $this->project->members()->attach($watcher);
    $this->project->subscribe($watcher); // subscribed at the project level

    Livewire::actingAs($this->member)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('moveTask', $this->task->id, Status::Done->value);

    Notification::assertSentTo($watcher, ItemActivity::class);
});
