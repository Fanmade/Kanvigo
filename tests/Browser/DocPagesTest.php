<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
});

it('renders the doc index and a doc page without browser errors', function () {
    $parent = Doc::factory()->for($this->project)->published()->create(['title' => 'Architecture']);
    Doc::factory()->childOf($parent)->create(['title' => 'Storage layer']);

    $task = Task::factory()->for($this->project)->create();
    $parent->addReference($task);

    $this->actingAs($this->user);

    $pages = visit([
        '/ABC/docs',
        '/ABC-D'.$parent->doc_number,
    ]);

    $pages->assertNoSmoke();
});

it('creates a doc from the index and opens it', function () {
    $this->actingAs($this->user);

    $page = visit('/ABC/docs');

    $page->assertVisible('@docs-empty')
        ->click('@new-doc')
        ->fill('@new-doc-title', 'Release checklist')
        ->click('@create-doc')
        ->assertSee('Release checklist')
        ->assertVisible('@edit-doc')
        ->assertNoJavascriptErrors();

    expect(Doc::where('title', 'Release checklist')->exists())->toBeTrue();
});

it('shows a doc reference in a task description as a live link', function () {
    $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Style guide']);
    $task = Task::factory()->for($this->project)->create([
        'description' => '<p>Follow '.inlineReference($doc).'.</p>',
    ]);

    $this->actingAs($this->user);

    $page = visit('/ABC-'.$task->task_number);

    // The stored reference renders as a real link to the doc, and the doc turns
    // up in the task's links panel as the sync recorded it.
    $page->assertVisible('.reference')
        ->assertVisible('@item-links')
        ->assertSee($doc->reference)
        ->click('.reference')
        ->assertSee('Style guide')
        ->assertNoJavascriptErrors();
});

it('publishes a draft from the doc page', function () {
    $doc = Doc::factory()->for($this->project)->create(['title' => 'Style guide']);

    $this->actingAs($this->user);

    $page = visit('/ABC-D'.$doc->doc_number);

    // The button's label flips once the round-trip lands, so waiting on it keeps
    // the database assertion from racing the Livewire request.
    $page->assertSeeIn('@toggle-doc-published', 'Draft')
        ->click('@toggle-doc-published')
        ->assertSeeIn('@toggle-doc-published', 'Published')
        ->assertNoJavascriptErrors();

    expect($doc->refresh()->is_public)->toBeTrue();
});
