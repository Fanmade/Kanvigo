<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;

it('adds an existing tag from the suggestion list', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $task = Task::factory()->for($project)->create();

    // An existing tag in this project so it shows up as a suggestion.
    Tag::factory()->for($project)->color('sky')->create(['name' => 'frontend']);

    $this->actingAs($user);

    $page = visit("/{$project->short_name}-{$task->task_number}");

    $page->assertMissing('@tag-input-field')
        ->click('@toggle-add-tag')
        ->assertVisible('@tag-input-field')
        ->fill('@tag-input-field', 'front')
        ->click('@tag-suggestion-frontend')
        ->waitForText('frontend')
        ->assertNoJavascriptErrors();

    expect($task->fresh()->tags->pluck('name')->all())->toBe(['frontend']);
});

it('creates a new tag with a chosen color through the modal', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $task = Task::factory()->for($project)->create();

    $this->actingAs($user);

    $page = visit("/{$project->short_name}-{$task->task_number}");

    $page->click('@toggle-add-tag')
        ->fill('@tag-input-field', 'Design')
        ->click('@tag-input-create')
        ->waitForText('New tag')
        ->assertValue('@new-tag-name', 'Design')
        ->click('@tag-color-violet')
        ->click('@create-tag')
        // Wait for the modal to close, which only happens after createTag has
        // saved and attached the tag server-side — unlike the visible name text,
        // which the modal's live Preview badge already shows before submitting.
        ->assertMissing('@new-tag-name')
        ->assertNoJavascriptErrors();

    $tag = Tag::where('name', 'Design')->sole();

    expect($tag->color)->toBe('violet')
        ->and($task->fresh()->tags->pluck('name')->all())->toBe(['Design']);
});
