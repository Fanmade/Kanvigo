<?php

use App\Models\Project;
use App\Models\User;

it('auto-refreshes project comments but pauses while the editor is focused', function () {
    config()->set('kanvigo.live_updates.interval_seconds', 1);

    $member = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Acme']);
    joinProject($project, [$member->id, $other->id]);

    $this->actingAs($member);

    $page = visit('/ABC');
    $page->assertSee('Acme');

    // A comment posted elsewhere shows up on the next tick.
    $project->comments()->create(['user_id' => $other->id, 'body' => '<p>Echo One</p>']);
    $page->waitForText('Echo One');

    // While the comment editor is focused, polling pauses so a draft isn't lost.
    // The composer is collapsed by default, so expand it first (this also focuses
    // the editor); the explicit focus keeps the intent clear and robust.
    $page->click('@comment-composer-trigger')
        ->script("document.querySelector('.ProseMirror')?.focus()");
    $project->comments()->create(['user_id' => $other->id, 'body' => '<p>Echo Two</p>']);
    $page->wait(2.5)->assertDontSee('Echo Two');

    // Blurring the editor lets the next tick catch up.
    $page->script('document.activeElement?.blur()');
    $page->waitForText('Echo Two')
        ->assertNoJavascriptErrors();
});
