<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\User;

it('renames and recolors a tag through the management page', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user, 'admin');
    $tag = Tag::factory()->for($project)->color('sky')->create(['name' => 'Bug']);

    $this->actingAs($user);

    $page = visit("/{$project->short_name}/tags");

    $page->assertSee('Bug')
        ->click("@edit-tag-{$tag->id}")
        ->waitForText('Edit tag')
        ->fill('@edit-tag-name', 'Defect')
        ->click('@edit-tag-color-rose')
        ->click('@save-tag')
        // The modal only closes once saveEdit() has persisted, so wait for it to
        // disappear — not for "Defect", which is already in the open editor.
        ->assertMissing('@edit-tag-name')
        ->assertSee('Defect')
        ->assertNoJavascriptErrors();

    expect($tag->fresh()->name)->toBe('Defect')
        ->and($tag->fresh()->color)->toBe('rose');
});

it('reaches the tag page from the project header', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit("/{$project->short_name}");

    $page->click('@project-actions')
        ->click('@manage-tags-link')
        ->waitForText('Rename, recolor, merge and delete')
        ->assertVisible('@project-tags')
        ->assertNoJavascriptErrors();
});
