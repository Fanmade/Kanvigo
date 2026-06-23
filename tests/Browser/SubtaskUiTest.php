<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a subtask from the task detail page and shows it in the list', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $parent = Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Parent task']);

    $this->actingAs($user);

    $page = visit('/'.$parent->reference);

    $page->assertNoJavascriptErrors()
        ->assertSee('Subtasks')
        ->click('@new-subtask')
        ->fill('@create-task-title', 'Build the thing')
        ->click('@create-task-submit')
        ->assertSee('Build the thing')
        ->assertNoJavascriptErrors();

    expect($parent->children()->count())->toBe(1)
        ->and($parent->children()->first()->title)->toBe('Build the thing');
});

it('assigns the creator from the create-task modal with one click', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $parent = Task::factory()->for($project)->status(Status::ToDo)->create(['title' => 'Parent task']);

    $this->actingAs($user);

    $page = visit('/'.$parent->reference);

    $page->click('@new-subtask')
        ->fill('@create-task-title', 'Assigned subtask')
        ->click('@create-task-assign-to-me')
        ->assertMissing('@create-task-assign-to-me')
        ->click('@create-task-submit')
        ->assertSee('Assigned subtask')
        ->assertNoJavascriptErrors();

    $task = $project->tasks()->where('title', 'Assigned subtask')->first();

    expect($task->assignees->pluck('id')->all())->toBe([$user->id]);
});

it('shows the subtree progress rollup on the detail page', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $parent = Task::factory()->for($project)->create();
    Task::factory()->for($project)->childOf($parent)->status(Status::Done)->create();
    Task::factory()->for($project)->childOf($parent)->status(Status::ToDo)->create();

    $this->actingAs($user);

    visit('/'.$parent->reference)
        ->assertNoJavascriptErrors()
        ->assertSee('1 / 2');
});
