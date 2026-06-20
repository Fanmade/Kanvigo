<?php

use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->user);
});

it('expands and collapses a long story description', function () {
    $story = Story::factory()->for($this->project)->create([
        'description' => str_repeat("A long paragraph of description text. \n\n", 60),
    ]);

    $this->actingAs($this->user);

    $page = visit('/'.$story->reference);

    // Overflowing content shows the toggle, starting collapsed.
    $page->assertVisible('@toggle-description')
        ->assertSee('Show more')
        ->click('@toggle-description')
        ->assertSee('Show less')
        ->click('@toggle-description')
        ->assertSee('Show more')
        ->assertNoJavascriptErrors();
});

it('does not show the toggle for a short description', function () {
    $story = Story::factory()->for($this->project)->create(['description' => 'Short and sweet.']);
    Task::factory()->for($story)->create();

    $this->actingAs($this->user);

    $page = visit('/'.$story->reference);

    $page->assertSee('Short and sweet.')
        ->assertDontSee('Show more')
        ->assertNoJavascriptErrors();
});
