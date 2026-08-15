<?php

use App\Models\User;

it('switches the theme from the sidebar account menu', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@sidebar-account-menu')
        ->click('@sidebar-account-theme-dark');

    expect($page->script('(() => document.documentElement.classList.contains("dark"))()'))->toBeTrue();

    $page->assertNoJavascriptErrors();
});

it('switches the theme from the top-right account menu and agrees with the settings page', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@header-account-menu')
        ->click('@header-account-theme-dark');

    expect($page->script('(() => document.documentElement.classList.contains("dark"))()'))->toBeTrue();

    // The Appearance page binds the same $flux.appearance store, so the choice
    // made in the menu survives the navigation and still applies there.
    $page->navigate('/settings/appearance');

    expect($page->script('(() => document.documentElement.classList.contains("dark"))()'))->toBeTrue();

    $page->assertNoJavascriptErrors();
});
