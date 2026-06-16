<?php

use App\Enums\Status;
use App\Livewire\Notifications\NotificationsMenu;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->watcher = User::factory()->create();
    $this->actor = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach([$this->watcher->id, $this->actor->id]);
    $this->story = Story::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->story)->status(Status::Planned)->create();
    $this->task->subscribe($this->watcher);

    // The actor moves the task, generating a notification for the watcher.
    Livewire::actingAs($this->actor)
        ->test(ProjectBoard::class, ['short_name' => 'ABC'])
        ->call('moveTask', $this->task->id, Status::Done->value);
});

it('shows the unread count and lists notifications', function () {
    $component = Livewire::actingAs($this->watcher)->test(NotificationsMenu::class);

    expect($component->instance()->unreadCount())->toBe(1)
        ->and($component->instance()->notifications())->toHaveCount(1);
});

it('marks all notifications as read', function () {
    Livewire::actingAs($this->watcher)
        ->test(NotificationsMenu::class)
        ->call('markAllRead');

    expect($this->watcher->unreadNotifications()->count())->toBe(0);
});

it('opens a notification, marks it read and redirects to the item', function () {
    $notification = $this->watcher->notifications()->first();

    Livewire::actingAs($this->watcher)
        ->test(NotificationsMenu::class)
        ->call('open', $notification->id)
        ->assertRedirect($notification->data['url']);

    expect($notification->fresh()->read_at)->not->toBeNull();
});
