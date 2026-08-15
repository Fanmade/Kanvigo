<?php

use App\Livewire\Notifications\NotificationInbox;
use App\Livewire\Notifications\NotificationsIndex;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

/**
 * A notification for the acting user, with control over the payload fields the
 * inbox filters on.
 */
function inboxNotification(User $user, string $reference = 'ABC-1', string $action = 'commented', bool $read = false, ?string $createdAt = null): Notification
{
    $notification = makeNotification($user, $reference, $read);

    $notification->forceFill([
        'data' => [...$notification->data, 'action' => $action],
        'created_at' => $createdAt ?? now(),
    ])->saveQuietly();

    return $notification->refresh();
}

it('shows the page with both tabs', function () {
    $this->actingAs($this->user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('data-test="tab-inbox"', false)
        ->assertSee('data-test="tab-subscriptions"', false);
});

it('defaults to the inbox tab', function () {
    Livewire::actingAs($this->user)
        ->test(NotificationsIndex::class)
        ->assertSet('tab', 'inbox');
});

it('lists the full history, not just the latest ten', function () {
    foreach (range(1, 25) as $i) {
        inboxNotification($this->user, "ABC-{$i}");
    }

    $component = Livewire::actingAs($this->user)->test(NotificationInbox::class);

    expect($component->instance()->notifications()->total())->toBe(25)
        ->and($component->instance()->notifications())->toHaveCount(20);
});

it('filters by read state', function () {
    $unread = inboxNotification($this->user, 'ABC-1');
    $read = inboxNotification($this->user, 'ABC-2', read: true);

    $listed = fn (string $status): array => Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->set('status', $status)
        ->instance()
        ->notifications()
        ->pluck('id')
        ->all();

    expect($listed('unread'))->toBe([$unread->id])
        ->and($listed('read'))->toBe([$read->id])
        ->and($listed('all'))->toHaveCount(2);
});

it('filters by project, including the project\'s own notifications', function () {
    $task = inboxNotification($this->user, 'ABC-7');
    $project = inboxNotification($this->user, 'ABC');
    inboxNotification($this->user, 'XYZ-1');

    $listed = Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->set('project', 'ABC')
        ->instance()
        ->notifications()
        ->pluck('id')
        ->all();

    expect($listed)->toHaveCount(2)
        ->and($listed)->toContain($task->id, $project->id);
});

it('filters by activity category', function () {
    $mention = inboxNotification($this->user, 'ABC-1', action: 'mentioned');
    inboxNotification($this->user, 'ABC-2', action: 'status_changed');

    $listed = Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->set('category', 'mentions')
        ->instance()
        ->notifications()
        ->pluck('id')
        ->all();

    expect($listed)->toBe([$mention->id]);
});

it('filters by period', function () {
    $recent = inboxNotification($this->user, 'ABC-1');
    inboxNotification($this->user, 'ABC-2', createdAt: now()->subDays(10)->toDateTimeString());

    $listed = Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->set('range', 'week')
        ->instance()
        ->notifications()
        ->pluck('id')
        ->all();

    expect($listed)->toBe([$recent->id]);
});

it('offers the user\'s projects in the project filter', function () {
    $mine = Project::factory()->create(['short_name' => 'ABC']);
    Project::factory()->create(['short_name' => 'XYZ']);
    joinProject($mine, $this->user);

    $projects = Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->instance()
        ->projects()
        ->pluck('short_name')
        ->all();

    expect($projects)->toBe(['ABC']);
});

it('marks a row read and unread again', function () {
    $notification = inboxNotification($this->user);

    $component = Livewire::actingAs($this->user)->test(NotificationInbox::class);

    $component->call('markRead', $notification->id);
    expect($notification->fresh()->read_at)->not->toBeNull();

    $component->call('markUnread', $notification->id);
    expect($notification->fresh()->read_at)->toBeNull();
});

it('dismisses a row', function () {
    $notification = inboxNotification($this->user);

    Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->call('dismiss', $notification->id);

    expect($this->user->notifications()->count())->toBe(0);
});

it('never touches another user\'s notification', function () {
    $other = User::factory()->create();
    $notification = inboxNotification($other);

    $component = Livewire::actingAs($this->user)->test(NotificationInbox::class);
    $component->call('markRead', $notification->id);
    $component->call('dismiss', $notification->id);

    expect($notification->fresh()->read_at)->toBeNull()
        ->and($other->notifications()->count())->toBe(1);
});

it('applies bulk actions to the selection only, and busts the cached count', function () {
    $selected = inboxNotification($this->user, 'ABC-1');
    $untouched = inboxNotification($this->user, 'ABC-2');

    expect($this->user->unreadNotificationCount())->toBe(2);

    $component = Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->set('selected', [$selected->id])
        ->call('markSelectedRead');

    expect($selected->fresh()->read_at)->not->toBeNull()
        ->and($untouched->fresh()->read_at)->toBeNull()
        ->and($this->user->unreadNotificationCount())->toBe(1)
        // The selection is cleared once acted on.
        ->and($component->get('selected'))->toBe([]);
});

it('dismisses the selection in bulk', function () {
    $selected = inboxNotification($this->user, 'ABC-1');
    $untouched = inboxNotification($this->user, 'ABC-2');

    Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->set('selected', [$selected->id])
        ->call('dismissSelected');

    expect($this->user->notifications()->pluck('id')->all())->toBe([$untouched->id])
        ->and($this->user->unreadNotificationCount())->toBe(1);
});

it('ignores a bulk action aimed at someone else\'s notification', function () {
    $other = User::factory()->create();
    $theirs = inboxNotification($other);

    Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->set('selected', [$theirs->id])
        ->call('dismissSelected');

    expect($other->notifications()->count())->toBe(1);
});

it('selects and deselects the current page', function () {
    $first = inboxNotification($this->user, 'ABC-1');
    $second = inboxNotification($this->user, 'ABC-2');

    $component = Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->call('toggleSelectPage');

    expect($component->get('selected'))->toHaveCount(2)
        ->and($component->get('selected'))->toContain($first->id, $second->id);

    $component->call('toggleSelectPage');

    expect($component->get('selected'))->toBe([]);
});

it('clears the selection when a filter changes', function () {
    $notification = inboxNotification($this->user);

    $component = Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->set('selected', [$notification->id])
        ->set('status', 'read');

    expect($component->get('selected'))->toBe([]);
});

it('opens a row, marking it read and redirecting to the item', function () {
    $notification = inboxNotification($this->user);
    $notification->forceFill(['data' => [...$notification->data, 'url' => '/ABC-1']])->saveQuietly();

    Livewire::actingAs($this->user)
        ->test(NotificationInbox::class)
        ->call('open', $notification->id)
        ->assertRedirect('/ABC-1');

    expect($notification->fresh()->read_at)->not->toBeNull();
});
