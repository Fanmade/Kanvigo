<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * KAN-494 / KAN-495: getting a task's address out of the app — its reference,
 * its absolute URL, or a markdown link — is one gesture. The clipboard is
 * stubbed because a headless browser grants no clipboard permission.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Ship the thing']);

    $this->actingAs($this->user);
});

/** Records what the page puts on the clipboard in `window.__copied`. */
$stubClipboard = <<<'JS'
    (() => {
        window.__copied = null;
        navigator.clipboard.writeText = (text) => {
            window.__copied = text;

            return Promise.resolve();
        };
    })()
JS;

it('copies the reference from the breadcrumb crumb', function () use ($stubClipboard) {
    $page = visit('/'.$this->task->reference);
    $page->script($stubClipboard);

    $page->click('@copy-reference')
        ->assertScript('(() => window.__copied)()', $this->task->reference)
        ->assertNoJavascriptErrors();
});

it('copies the link and the markdown link from the actions menu', function () use ($stubClipboard) {
    $url = route('task.show', ['short_name' => 'ABC', 'task_number' => $this->task->task_number]);

    $page = visit('/'.$this->task->reference);
    $page->script($stubClipboard);

    $page->click('@task-actions')
        ->click('@copy-link')
        ->assertScript('(() => window.__copied)()', $url);

    $page->click('@task-actions')
        ->click('@copy-markdown-link')
        ->assertScript('(() => window.__copied)()', "[{$this->task->reference} Ship the thing]({$url})");

    $page->click('@task-actions')
        ->click('@copy-reference-menu')
        ->assertScript('(() => window.__copied)()', $this->task->reference)
        ->assertNoJavascriptErrors();
});

it('offers the copy actions to a member who cannot edit the task', function () {
    $viewer = User::factory()->create();
    joinProject($this->project, $viewer, 'viewer');

    $this->actingAs($viewer);

    visit('/'.$this->task->reference)
        ->click('@task-actions')
        ->assertVisible('@copy-link')
        ->assertMissing('@cancel-task')
        ->assertNoJavascriptErrors();
});
