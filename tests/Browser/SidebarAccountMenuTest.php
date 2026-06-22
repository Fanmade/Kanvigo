<?php

use App\Models\User;

it('opens the bottom-left account menu and reaches settings', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@sidebar-account-menu')
        ->click('@sidebar-account-settings')
        ->assertVisible('@avatar-section')
        ->assertPathIs('/settings/profile')
        ->assertNoJavascriptErrors();
});

it('logs out from the bottom-left account menu', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@sidebar-account-menu')
        ->click('@sidebar-account-logout')
        ->assertVisible('@login-button')
        ->assertPathIs('/login')
        ->assertNoJavascriptErrors();

    $this->assertGuest();
});
