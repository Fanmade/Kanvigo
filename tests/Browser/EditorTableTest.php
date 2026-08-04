<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create();
});

it('inserts a table of the picked size via the toolbar grid picker', function () {
    $this->actingAs($this->user);

    $page = visit('/'.$this->task->reference);

    $page->click('@edit-task')
        ->assertVisible('@editor-table-button')
        ->click('@editor-table-button')
        ->assertVisible('@table-size-2x3')
        ->click('@table-size-2x3');

    // A 2×3 table (header row + body row) is inserted into the editor content.
    $page->assertVisible('[data-flux-editor] table')
        ->assertVisible('[data-flux-editor] table th:nth-child(3)')
        ->assertNoJavascriptErrors();
});

it('edits a table via the toolbar edit menu', function () {
    $this->actingAs($this->user);

    $page = visit('/'.$this->task->reference);

    // Each action returns focus to the editor, which closes the popover
    // asynchronously — wait for the close (assertMissing) before clicking the
    // trigger again, or the click lands mid-close and toggles it shut.
    $page->click('@edit-task')
        ->click('@editor-table-button')
        ->click('@table-size-2x3')
        ->assertMissing('@table-size-2x3');

    // With the caret inside the freshly inserted table, the same toolbar button
    // opens the edit menu instead of the grid picker.
    $page->click('@editor-table-button')
        ->assertVisible('@table-edit-menu')
        ->click('@table-add-row-below')
        ->assertVisible('[data-flux-editor] table tr:nth-of-type(3)')
        ->assertMissing('@table-edit-menu');

    $page->click('@editor-table-button')
        ->click('@table-delete')
        ->assertMissing('[data-flux-editor] table')
        ->assertNoJavascriptErrors();
});

it('keeps an inserted table when the description is saved and reopened', function () {
    $this->actingAs($this->user);

    $page = visit('/'.$this->task->reference);

    $page->click('@edit-task')
        ->click('@editor-table-button')
        ->click('@table-size-2x3')
        ->click('@save-task');

    // The table round-trips: it is saved with the description HTML and comes
    // back intact when the editor is reopened.
    $page->click('@edit-task')
        ->assertVisible('[data-flux-editor] table')
        ->assertNoJavascriptErrors();
});
