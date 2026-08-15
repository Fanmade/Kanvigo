<?php

use App\Models\User;
use Illuminate\Support\Str;

it('keeps the notifications menu working across SPA navigation', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('dashboard'));

    $page->assertVisible('@notifications-trigger')
        ->click('@nav-projects') // wire:navigate transition, no full page reload
        ->assertPathIs('/projects')
        ->assertVisible('@notifications-trigger')
        ->click('@notifications-trigger')
        ->assertVisible('@notifications-panel')
        ->assertNoJavascriptErrors();
});

it('opens the account menu from the avatar, next to the bell', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('dashboard'));

    $page->assertVisible('@notifications-trigger')
        ->click('@header-account-menu')
        ->click('@header-account-settings')
        ->assertPathIs('/settings/profile')
        ->assertNoJavascriptErrors();
});

it('dismisses a notification from the panel without following its link', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['url' => route('projects.index'), 'reference' => 'ABC-1', 'action' => 'commented'],
        'read_at' => null,
    ]);

    $page = visit(route('dashboard'));

    $page->click('@notifications-trigger')
        ->assertVisible("@notification-{$notification->id}")
        ->click("@dismiss-notification-{$notification->id}")
        // Dismissing must not trigger the row's own open() — we stay put.
        ->assertPathIs('/dashboard')
        ->assertMissing("@notification-{$notification->id}")
        ->assertNoJavascriptErrors();

    expect($user->notifications()->count())->toBe(0);
});
