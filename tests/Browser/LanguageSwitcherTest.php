<?php

use App\Models\User;

it('switches the language from the sidebar account menu', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@sidebar-account-menu')
        ->click('@sidebar-account-language-de')
        // The page comes back rendered in German — the sidebar nav is the
        // cheapest proof the server re-rendered in the new locale.
        ->assertSeeIn('@nav-notifications', __('Notifications', locale: 'de'))
        ->assertNoJavascriptErrors();
});

it('switches back to English from the top-right account menu', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@header-account-menu')
        ->click('@header-account-language-de')
        ->assertSeeIn('@nav-notifications', __('Notifications', locale: 'de'))
        ->click('@header-account-menu')
        ->click('@header-account-language-en')
        ->assertSeeIn('@nav-notifications', 'Notifications')
        ->assertNoJavascriptErrors();
});
