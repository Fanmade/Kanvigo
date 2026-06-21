<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->user);
});

it('expands and collapses a long task description', function () {
    $task = Task::factory()->for($this->project)->create([
        'description' => str_repeat("A long paragraph of description text. \n\n", 60),
    ]);

    $this->actingAs($this->user);

    $page = visit('/'.$task->reference);

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
    $task = Task::factory()->for($this->project)->create(['description' => 'Short and sweet.']);

    $this->actingAs($this->user);

    $page = visit('/'.$task->reference);

    $page->assertSee('Short and sweet.')
        ->assertDontSee('Show more')
        ->assertNoJavascriptErrors();
});
