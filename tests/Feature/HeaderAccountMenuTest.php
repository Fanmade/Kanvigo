<?php

use App\Livewire\Notifications\NotificationsMenu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the notifications bell and the account menu as separate header controls', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="notifications-trigger"', false)
        ->assertSee('data-test="header-account-menu"', false)
        ->assertSee('data-test="header-account-profile"', false);
});

it('keeps the account items out of the notifications panel', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(NotificationsMenu::class)
        ->assertDontSee('View profile')
        ->assertDontSee('Log out')
        ->assertSee('Manage notifications');
});

it('shows the unread badge on the bell', function () {
    $user = User::factory()->create();
    makeNotification($user);

    Livewire::actingAs($user)
        ->test(NotificationsMenu::class)
        ->assertSee('data-test="notifications-badge"', false)
        ->assertSee('1');
});

it('hides the badge when nothing is unread', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(NotificationsMenu::class)
        ->assertDontSee('data-test="notifications-badge"', false);
});
