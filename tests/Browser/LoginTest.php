<?php

use App\Models\User;

it('logs a user in through the login form', function () {
    $user = User::factory()->create();

    $page = visit('/login');

    // Wait for the passkey button to render before filling. It only appears once
    // passkeys.js has loaded and Alpine has flipped `supported` to true, which
    // injects DOM above the form. Filling before that injection settles can clobber
    // the email field and leave the form un-submittable (stuck on /login).
    $page->assertVisible('@passkey-verify')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->assertVisible('@dashboard-heading')
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();

    $this->assertAuthenticatedAs($user);
});
