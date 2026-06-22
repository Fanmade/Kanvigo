<?php

use App\Models\User;

it('captures a note from the dashboard and lists it', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('@dashboard-new-note')
        ->fill('@create-note-title', 'Browser captured idea')
        ->click('@create-note-submit')
        ->waitForText('Browser captured idea') // appears in the Notes panel
        ->assertNoJavascriptErrors();
});
