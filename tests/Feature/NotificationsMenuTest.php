<?php

use App\Enums\Status;
use App\Livewire\Notifications\NotificationsMenu;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->watcher = User::factory()->create();
    $this->actor = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, [$this->watcher->id, $this->actor->id]);
    $this->task = Task::factory()->for($this->project)->status(Status::Planned)->create();
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

it('formats the unread badge, hiding it at zero and capping it at "9+"', function (int $unread, ?string $expected) {
    $user = User::factory()->create();

    for ($i = 0; $i < $unread; $i++) {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['url' => null, 'reference' => 'ABC-1'],
            'read_at' => null,
        ]);
    }

    $component = Livewire::actingAs($user)->test(NotificationsMenu::class);

    expect($component->instance()->unreadBadge())->toBe($expected);
})->with([
    'none' => [0, null],
    'a few' => [3, '3'],
    'exactly nine' => [9, '9'],
    'capped' => [12, '9+'],
]);

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

it('caches the unread count and busts it as notifications change', function () {
    $user = User::factory()->create();

    expect($user->unreadNotificationCount())->toBe(0);

    // A new notification fires the created event, busting the cache.
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['url' => null, 'reference' => 'ABC-1'],
        'read_at' => null,
    ]);
    expect($user->unreadNotificationCount())->toBe(1);

    // Marking it read fires the updated event, busting the cache again.
    $user->unreadNotifications->markAsRead();
    expect($user->unreadNotificationCount())->toBe(0);

    // Deleting an unread notification fires the deleted event, busting it too.
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['url' => null, 'reference' => 'ABC-2'],
        'read_at' => null,
    ]);
    expect($user->unreadNotificationCount())->toBe(1);

    $user->unreadNotifications()->first()->delete();
    expect($user->unreadNotificationCount())->toBe(0);
});
