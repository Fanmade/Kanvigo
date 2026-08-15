<?php

use App\Models\User;

it('switches between the inbox and subscriptions tabs', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    makeNotification($user);

    $page = visit(route('notifications.index'));

    $page->assertVisible('@notification-inbox')
        ->click('@tab-subscriptions')
        ->assertVisible('@subscription-settings')
        ->click('@tab-inbox')
        ->assertVisible('@notification-inbox')
        ->assertNoJavascriptErrors();
});

it('dismisses a notification from the inbox', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $notification = makeNotification($user);

    $page = visit(route('notifications.index'));

    $page->assertVisible("@inbox-notification-{$notification->id}")
        ->click("@inbox-dismiss-{$notification->id}")
        ->assertMissing("@inbox-notification-{$notification->id}")
        ->assertVisible('@inbox-empty')
        ->assertNoJavascriptErrors();
});

it('bulk-marks the selected notifications read', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $first = makeNotification($user, 'ABC-1');
    makeNotification($user, 'ABC-2');

    $page = visit(route('notifications.index'));

    $page->click("@select-notification-{$first->id}")
        ->assertVisible('@bulk-mark-read')
        ->click('@bulk-mark-read')
        // Wait on the re-render before asserting on the database: the row's
        // action flips to "mark unread" and the selection (with its bulk
        // buttons) clears only once the Livewire roundtrip has landed.
        ->assertVisible("@mark-unread-{$first->id}")
        ->assertMissing('@bulk-mark-read')
        ->assertNoJavascriptErrors();

    expect($first->fresh()->read_at)->not->toBeNull()
        ->and($user->unreadNotifications()->count())->toBe(1);
});
