<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;

it('changes a task status from the badge dropdown', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $project->members()->attach($user);
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->status(Status::Planned)->create();

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
    $story = Story::factory()->for($project)->create();
    $task = Task::factory()->for($story)->create();

    $this->actingAs($user);

    $page = visit("/{$project->short_name}-{$task->task_number}");

    // The reference input is hidden until the user opts into adding a dependency.
    $page->assertMissing('@dependency-reference')
        ->click('@toggle-add-dependency')
        ->assertVisible('@dependency-reference')
        ->assertNoJavascriptErrors();
});
