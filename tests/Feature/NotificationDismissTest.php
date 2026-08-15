<?php

use App\Livewire\Notifications\NotificationsMenu;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Create a notification for a user, optionally already read.
 */
function makeNotification(User $user, ?string $reference = 'ABC-1', bool $read = false): Notification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['url' => null, 'reference' => $reference],
        'read_at' => $read ? now() : null,
    ]);
}

it('dismisses a single notification without hard-deleting it', function () {
    $user = User::factory()->create();
    $notification = makeNotification($user);

    Livewire::actingAs($user)
        ->test(NotificationsMenu::class)
        ->call('dismiss', $notification->id);

    expect($user->notifications()->count())->toBe(0)
        ->and(Notification::withTrashed()->whereKey($notification->id)->first()->deleted_at)->not->toBeNull();
});

it('does not dismiss another user\'s notification', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $notification = makeNotification($other);

    Livewire::actingAs($user)
        ->test(NotificationsMenu::class)
        ->call('dismiss', $notification->id);

    expect($other->notifications()->count())->toBe(1);
});

it('clears all notifications and busts the cached unread count', function () {
    $user = User::factory()->create();
    makeNotification($user);
    makeNotification($user, 'ABC-2', read: true);

    // Warm the cache so a stale value would survive the bulk soft-delete, which
    // fires no model events.
    expect($user->unreadNotificationCount())->toBe(1);

    Livewire::actingAs($user)
        ->test(NotificationsMenu::class)
        ->call('clearAll');

    expect($user->notifications()->count())->toBe(0)
        ->and($user->unreadNotificationCount())->toBe(0)
        ->and(Notification::withTrashed()->count())->toBe(2);
});

it('clears only the acting user\'s notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    makeNotification($user);
    makeNotification($other);

    Livewire::actingAs($user)
        ->test(NotificationsMenu::class)
        ->call('clearAll');

    expect($other->notifications()->count())->toBe(1);
});

it('lists unread notifications before read ones, newest first', function () {
    $user = User::factory()->create();

    $oldRead = makeNotification($user, 'ABC-1', read: true);
    $oldRead->forceFill(['created_at' => now()->subDays(3)])->save();

    $newRead = makeNotification($user, 'ABC-2', read: true);
    $newRead->forceFill(['created_at' => now()->subDay()])->save();

    $unread = makeNotification($user, 'ABC-3');
    $unread->forceFill(['created_at' => now()->subDays(2)])->save();

    $listed = Livewire::actingAs($user)
        ->test(NotificationsMenu::class)
        ->instance()
        ->notifications()
        ->pluck('id')
        ->all();

    expect($listed)->toBe([$unread->id, $newRead->id, $oldRead->id]);
});

it('hides dismissed notifications from the panel and the unread count', function () {
    $user = User::factory()->create();
    $notification = makeNotification($user);

    $notification->delete();

    expect($user->unreadNotificationCount())->toBe(0)
        ->and(Livewire::actingAs($user)->test(NotificationsMenu::class)->instance()->notifications())->toHaveCount(0);
});

it('prunes notifications dismissed longer than a month ago', function () {
    $user = User::factory()->create();

    $stale = makeNotification($user, 'ABC-1');
    $stale->delete();
    $stale->forceFill(['deleted_at' => now()->subMonth()->subDay()])->saveQuietly();

    $recent = makeNotification($user, 'ABC-2');
    $recent->delete();

    // A notification that was never dismissed is never pruned, however old.
    $kept = makeNotification($user, 'ABC-3');
    $kept->forceFill(['created_at' => now()->subYear()])->save();

    $pruned = (new Notification)->prunable()->pluck('id')->all();

    expect($pruned)->toBe([$stale->id]);
});
