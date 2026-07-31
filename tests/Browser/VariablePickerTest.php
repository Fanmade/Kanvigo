<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Picker task']);
});

it('offers the project variables when a bracket is typed, and inserts the usage', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $this->actingAs($this->user);

    $page = visit('/ABC-'.$this->task->task_number);

    $page->click('@comment-composer-trigger')
        ->assertScript("!! document.querySelector('[data-flux-editor]')?.__editor")
        ->click('form[x-ref="composer"] .ProseMirror')
        ->typeSlowly('form[x-ref="composer"] .ProseMirror', 'Enter [her')
        ->assertVisible('.mention-suggestions')
        ->assertSeeIn('.mention-suggestions', 'Robin Hood')
        ->keys('form[x-ref="composer"] .ProseMirror', ['Enter'])
        // A usage is plain text, so the editor shows the raw name — never the value.
        ->assertSeeIn('form[x-ref="composer"] .ProseMirror', '[hero]')
        ->assertDontSeeIn('form[x-ref="composer"] .ProseMirror', 'Robin Hood')
        ->assertNoJavascriptErrors();
});

it('leaves an ordinary bracket alone when nothing matches', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $this->actingAs($this->user);

    $page = visit('/ABC-'.$this->task->task_number);

    $page->click('@comment-composer-trigger')
        ->assertScript("!! document.querySelector('[data-flux-editor]')?.__editor")
        ->click('form[x-ref="composer"] .ProseMirror')
        // A footnote marker is not a usage: nothing is offered and the text stands.
        ->typeSlowly('form[x-ref="composer"] .ProseMirror', 'See note [1]')
        ->assertSeeIn('form[x-ref="composer"] .ProseMirror', 'See note [1]')
        ->assertNoJavascriptErrors();
});

it('defines a variable from the editor and inserts the usage it created', function () {
    $this->actingAs($this->user);

    $page = visit('/ABC-'.$this->task->task_number);

    $page->click('@comment-composer-trigger')
        ->assertScript("!! document.querySelector('[data-flux-editor]')?.__editor")
        ->click('form[x-ref="composer"] .ProseMirror')
        ->typeSlowly('form[x-ref="composer"] .ProseMirror', 'Enter [villain')
        ->assertVisible('.mention-suggestions')
        ->keys('form[x-ref="composer"] .ProseMirror', ['Enter'])
        ->assertVisible('@create-variable-name')
        ->fill('@create-variable-value', 'The Sheriff')
        ->click('@save-new-variable')
        ->assertMissing('@create-variable-name')
        ->assertSeeIn('form[x-ref="composer"] .ProseMirror', '[villain]')
        ->assertNoJavascriptErrors();

    expect($this->project->variables()->first())
        ->name->toBe('villain')
        ->value->toBe('The Sheriff');
});
