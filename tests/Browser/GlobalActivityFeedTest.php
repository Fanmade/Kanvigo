<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('opens the activity feed from the sidebar and links a row to its task', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $task = Task::factory()->for($project)->create(['title' => 'Ship it']);

    $this->actingAs($user);

    $page = visit(route('dashboard'));

    $page->click('@nav-activity')
        ->assertVisible('@global-activity-feed')
        ->assertVisible('@activity-row')
        ->assertSeeIn('@activity-subject', $task->reference)
        ->click('@activity-subject')
        ->assertSee('Ship it')
        ->assertNoJavascriptErrors();
});
