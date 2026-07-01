<?php

use App\Models\Note;
use App\Models\Project;
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

it('renders public notes in the project Notes section', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $owner);
    $note = Note::factory()->for($owner)->publicTo($project)->create(['title' => 'Shared on the project']);

    $this->actingAs($owner);

    visit('/ABC')
        ->assertVisible('@project-notes')
        ->assertSeeIn('@public-note-'.$note->id, 'Shared on the project')
        ->assertNoJavascriptErrors();
});
