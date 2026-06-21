<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('changes a task status from the badge dropdown', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($user);
    $task = Task::factory()->for($project)->status(Status::Planned)->create();

    $this->actingAs($user);

    $page = visit("/{$project->short_name}-{$task->task_number}");

    $page->assertSee('Planned')
        ->click('@status-control')
        ->click('@status-option-Done')
        ->waitForText('Done')
        ->assertNoJavascriptErrors();

    expect($task->fresh()->status)->toBe(Status::Done);
});

it('reveals the dependency form only when adding', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($user);
    $task = Task::factory()->for($project)->create();

    $this->actingAs($user);

    $page = visit("/{$project->short_name}-{$task->task_number}");

    // The reference input is hidden until the user opts into adding a dependency.
    $page->assertMissing('@dependency-reference')
        ->click('@toggle-add-dependency')
        ->assertVisible('@dependency-reference')
        ->assertNoJavascriptErrors();
});

it('keeps the blocked dependencies header within the sidebar in German', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($user);
    $task = Task::factory()->for($project)->create();
    $task->addBlocker(Task::factory()->for($project)->status(Status::Planned)->create());

    $this->actingAs($user);

    // German labels (Abhängigkeiten / Blockiert / Hinzufügen) are longer than the
    // English ones and used to overflow the fixed-width sidebar panel.
    $page = visit("/{$project->short_name}-{$task->task_number}")->withLocale('de-DE');

    $page->assertSee('Abhängigkeiten')
        ->assertVisible('@blocked-badge')
        ->assertScript(
            "(() => { const el = document.querySelector('[data-test=dependencies]'); return el.scrollWidth <= el.clientWidth; })()",
        )
        ->assertNoJavascriptErrors();
});
