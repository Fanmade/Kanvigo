<?php

use App\Enums\Status;
use App\Livewire\Projects\ProjectBoard;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('auto-refreshes the board and suspends while dragging', function () {
    // Poll quickly so the test doesn't wait on the 15s default.
    config()->set('kanvigo.live_updates.interval_seconds', 1);

    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($user);
    Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Task Alpha']);

    $this->actingAs($user);

    $page = visit('/ABC/board');
    $page->assertSee('Task Alpha');

    // A change made elsewhere appears on the next poll, no reload.
    Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Task Bravo']);
    $page->waitForText('Task Bravo');

    // While a card is being dragged, polling is suspended so the DOM isn't
    // morphed out from under the drag.
    $page->script("document.body.classList.add('kanban-dragging')");
    Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Task Charlie']);
    $page->wait(2.5)->assertDontSee('Task Charlie');

    // Once the drag ends, the next poll catches up.
    $page->script("document.body.classList.remove('kanban-dragging')");
    $page->waitForText('Task Charlie')
        ->assertNoJavascriptErrors();
});

it('does not auto-refresh while live updates are off', function () {
    config()->set('kanvigo.live_updates.interval_seconds', 1);

    $user = User::factory()->create();
    // Start with live updates off, so the board never polls from the first render
    // (avoids racing a tick against the toggle's round-trip).
    $user->setPreference(ProjectBoard::LIVE_UPDATES_PREFERENCE_KEY, false);
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($user);
    Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Task Alpha']);

    $this->actingAs($user);

    $page = visit('/ABC/board');
    $page->assertSee('Task Alpha')
        ->assertDontSee('Task Delta');

    Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Task Delta']);
    $page->wait(2.5)->assertDontSee('Task Delta')
        ->assertNoJavascriptErrors();
});
