<?php

use App\Actions\SetWaitingOn;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Switching tabs is a client-side interaction on top of a `wire:model.live`
 * round-trip, so a broken tab only shows up in a real browser: the server-side
 * render was correct throughout the blank-panel bug (KAN-574).
 */
it('keeps the list visible when switching between the two tabs', function () {
    $me = User::factory()->create();
    $asker = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Alpha']);
    joinProject($project, [$me->id, $asker->id]);

    $waitingOnMe = Task::factory()->for($project)->create(['title' => 'Needs my answer']);

    Auth::login($asker);
    app(SetWaitingOn::class)->handle($waitingOnMe, $me);
    Auth::logout();

    $this->actingAs($me);

    $page = visit('/waiting');
    $page->assertVisible('@waiting-item-'.$waitingOnMe->id);

    // Nothing is waiting on somebody else for this user, so the other tab shows
    // its empty state — not a blank area.
    $page->click('@tab-by-me')
        ->assertVisible('@waiting-empty')
        ->assertMissing('@waiting-item-'.$waitingOnMe->id);

    // ...and coming back restores the item without a reload.
    $page->click('@tab-on-me')
        ->assertVisible('@waiting-item-'.$waitingOnMe->id)
        ->assertNoJavascriptErrors();
});
