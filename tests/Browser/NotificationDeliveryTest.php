<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The delivery tab is a new panel on an existing Flux tab group. KAN-574 was
 * exactly this shape of bug — a panel that renders correctly server-side and
 * still comes up blank in a browser — so the tab is exercised for real.
 */
it('switches to the delivery tab and toggles e-mail updates', function () {
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $user = User::factory()->create(['email_verified_at' => Carbon::now()]);
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit('/notifications');
    $page->assertVisible('@notification-inbox');

    $page->click('@tab-delivery')
        ->assertVisible('@delivery-settings')
        ->assertMissing('@delivery-unverified');

    // The per-level switches only become operable once the master switch is on.
    // That marker appears on the Livewire round-trip, so waiting for it is the
    // barrier proving the preference was written — reading the database straight
    // after the click races the request.
    $page->click('@email-toggle')
        ->assertVisible('@delivery-levels-active')
        ->assertNoJavascriptErrors();

    expect($user->fresh()->preference(User::EMAIL_PREFERENCE_KEY))->toBeTrue();
});
