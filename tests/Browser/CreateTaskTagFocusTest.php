<?php

use App\Models\Project;
use App\Models\User;

/**
 * KAN-225: opening the tag picker in the create-task dialog should focus the
 * search input straight away, so adding a tag is a single action (no extra click
 * into the field).
 */
it('focuses the tag search input when the picker opens', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit('/ABC/board');
    $page->click('@new-task')
        ->wait(1)
        ->click('@create-task-add-tag')
        ->wait(0.6)
        ->assertScript("document.activeElement?.getAttribute('data-test') === 'create-task-tag-input'")
        ->assertNoJavascriptErrors();
});
