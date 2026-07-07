<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\User;

/**
 * KAN-303: synonyms are managed from the tag edit dialog — typed and added with
 * Enter, then persisted on save so the tag is found by them when searching.
 */
it('adds a synonym from the edit dialog and persists it on save', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user, 'admin');
    $tag = Tag::factory()->for($project)->color('sky')->create(['name' => 'Research']);

    $this->actingAs($user);

    $page = visit("/{$project->short_name}/tags");

    $page->assertSee('Research')
        ->click("@edit-tag-{$tag->id}")
        ->waitForText('Edit tag')
        ->type('@edit-tag-synonym-input', 'Evaluation')
        ->keys('@edit-tag-synonym-input', 'Enter')
        ->wait(0.6)
        ->assertVisible('@remove-synonym-0')
//        ->screenshot(false, 'tag-synonyms-edit')
        ->click('@save-tag')
        ->assertMissing('@edit-tag-name')
        ->assertNoJavascriptErrors();

    expect($tag->fresh()->synonyms()->pluck('name')->all())->toBe(['Evaluation']);
});
