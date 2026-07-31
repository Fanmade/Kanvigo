<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;

it('creates a variable through the management page', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit("/{$project->short_name}/variables");

    $page->assertVisible('@variables-empty')
        ->click('@new-variable-empty')
        ->fill('@edit-variable-name', 'main_protagonist')
        ->fill('@edit-variable-value', 'Robin Hood')
        ->click('@save-variable')
        // The modal only closes once save() has persisted.
        ->assertMissing('@edit-variable-name')
        ->assertVisible('@variables-list')
        ->assertNoJavascriptErrors();

    expect($project->variables()->first()->value)->toBe('Robin Hood');
});

it('shows a variable value in a task description and its name when unset', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    Variable::factory()->for($project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    Variable::factory()->for($project)->unset()->create(['name' => 'villain']);

    $task = Task::factory()->for($project)->create([
        'title' => 'Scene one',
        'description' => '<p>[hero] meets [villain].</p>',
    ]);

    $this->actingAs($user);

    $page = visit("/{$project->short_name}-{$task->task_number}");

    $page->assertSee('Robin Hood')
        ->assertSee('villain')
        ->assertDontSee('[hero]')
        ->assertNoJavascriptErrors();
});

it('reaches the variables page from the project header', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit("/{$project->short_name}");

    $page->click('@project-actions')
        ->click('@manage-variables-link')
        ->assertVisible('@project-variables')
        ->assertNoJavascriptErrors();
});
