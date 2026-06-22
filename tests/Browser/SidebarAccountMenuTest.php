<?php

use App\Models\User;

it('opens the bottom-left account menu and reaches settings', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@sidebar-account-menu')
        ->click('@sidebar-account-settings')
        ->assertPathIs('/settings/profile')
        ->assertNoJavascriptErrors();
});

it('logs out from the bottom-left account menu', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@sidebar-account-menu')
        ->click('@sidebar-account-logout')
        ->assertPathIs('/login')
        ->assertNoJavascriptErrors();

    $this->assertGuest();
});
