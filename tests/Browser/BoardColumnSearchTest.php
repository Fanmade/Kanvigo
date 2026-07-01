<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * KAN-282: the per-column search collapses to an icon to keep the board header
 * compact, expanding to a focused input on demand.
 */
it('expands the column search on click, focuses it, and filters the lane', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Task Alpha']);

    $this->actingAs($user);

    $page = visit('/ABC/board');

    // Collapsed by default: the search is a single icon, the input is hidden.
    $page->assertSee('Task Alpha')
        ->assertVisible('@column-search-toggle-ToDo')
        ->assertMissing('@column-search-ToDo')
        // Clicking the icon reveals the input and focuses it (no second click).
        ->click('@column-search-toggle-ToDo')
        ->assertVisible('@column-search-ToDo')
        ->assertScript("document.activeElement?.closest('[data-test=\"column-search-ToDo\"]') !== null")
        // The revealed input still filters its lane.
        ->fill('@column-search-ToDo', 'no-such-task')
        ->assertDontSee('Task Alpha')
        ->assertNoJavascriptErrors();
});
