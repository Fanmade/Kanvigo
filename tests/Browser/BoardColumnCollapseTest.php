<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('collapses a board column on a mobile viewport', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $task = Task::factory()->for($project)->create([
        'title' => 'Draft the outline',
        'status' => Status::ToDo,
    ]);

    $this->actingAs($user);

    visit("/{$project->short_name}/board")->on()->mobile()
        ->assertSee($task->title)
        ->click('@column-collapse-ToDo')
        // The header and its count stay; only the card list is hidden.
        ->assertVisible('@column-ToDo')
        ->assertDontSee($task->title)
        ->click('@column-collapse-ToDo')
        ->assertSee($task->title)
        ->assertNoJavascriptErrors();
});

it('keeps every column expanded on a desktop viewport', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $task = Task::factory()->for($project)->create([
        'title' => 'Draft the outline',
        'status' => Status::ToDo,
    ]);

    $this->actingAs($user);

    // The collapse control is a small-screen affordance and is hidden from `md` up.
    visit("/{$project->short_name}/board")
        ->assertMissing('@column-collapse-ToDo')
        ->assertSee($task->title)
        ->assertNoJavascriptErrors();
});
