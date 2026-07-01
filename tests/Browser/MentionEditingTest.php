<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->other = User::factory()->create(['name' => 'Benjamin Reuter']);
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, [$this->user->id, $this->other->id]);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Mention task']);
});

it('keeps a mention linked when its trailing words are trimmed', function () {
    $this->actingAs($this->user);
    $id = $this->other->id;

    $page = visit('/ABC-'.$this->task->task_number);

    $page->assertSee('Mention task')
        ->click('@comment-composer-trigger')
        ->assertScript("!! document.querySelector('[data-flux-editor]')?.__editor")
        ->script("document.querySelector('.ProseMirror')?.focus()");

    // Insert a full mention "@Benjamin Reuter" (mirrors picking a suggestion).
    $page->script(<<<JS
    (() => {
        const editor = document.querySelector('[data-flux-editor]')?.__editor;
        editor.chain().focus().insertContent([
            { type: 'text', text: '@Benjamin Reuter', marks: [{ type: 'mention', attrs: { id: '{$id}' } }] },
            { type: 'text', text: ' ' },
        ]).run();
    })();
    JS);

    $page->assertVisible('.ProseMirror span.mention')
        ->assertSeeIn('.ProseMirror span.mention', '@Benjamin Reuter');

    // Trim the trailing word: delete " Reuter". The mark and its data-id must
    // survive on the shortened "@Benjamin".
    $page->script(<<<'JS'
    (() => {
        const editor = document.querySelector('[data-flux-editor]')?.__editor;
        let target = null;
        editor.state.doc.descendants((node, pos) => {
            if (node.isText && node.marks.some((m) => m.type.name === 'mention')) {
                target = { pos, text: node.text };
            }
        });
        const cut = target.text.lastIndexOf(' ');
        editor.chain().focus()
            .setTextSelection({ from: target.pos + cut, to: target.pos + target.text.length })
            .deleteSelection()
            .run();
    })();
    JS);

    $page->assertVisible('.ProseMirror span.mention')
        ->assertSeeIn('.ProseMirror span.mention', '@Benjamin')
        ->assertNoJavascriptErrors();
});

it('drops the mention cleanly when its leading @ is deleted', function () {
    $this->actingAs($this->user);
    $id = $this->other->id;

    $page = visit('/ABC-'.$this->task->task_number);

    $page->click('@comment-composer-trigger')
        ->assertScript("!! document.querySelector('[data-flux-editor]')?.__editor")
        ->script("document.querySelector('.ProseMirror')?.focus()");

    $page->script(<<<JS
    (() => {
        const editor = document.querySelector('[data-flux-editor]')?.__editor;
        editor.chain().focus().insertContent([
            { type: 'text', text: '@Ben', marks: [{ type: 'mention', attrs: { id: '{$id}' } }] },
        ]).run();
        // Delete the leading '@' (the first character), leaving "Ben".
        editor.chain().focus().setTextSelection({ from: 1, to: 2 }).deleteSelection().run();
    })();
    JS);

    // The "starts with @" invariant strips the mark, so "Ben" is plain text.
    $page->assertMissing('.ProseMirror span.mention')
        ->assertSeeIn('.ProseMirror', 'Ben')
        ->assertNoJavascriptErrors();
});
