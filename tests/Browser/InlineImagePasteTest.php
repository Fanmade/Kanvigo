<?php

use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * The bug: on mobile (Android Chrome) a pasted image lands in
 * `clipboardData.items`, not `clipboardData.files`, so the editor's old
 * files-only extraction found nothing and the paste was dropped. This asserts
 * the `richEditor` component's `filesFrom()` now reads both, while still
 * ignoring non-image content. (The subsequent Livewire upload is covered by the
 * inline-attachment feature tests.)
 */
it('extracts pasted images from clipboard items as well as files', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user);
    $task = Task::factory()->for($project)->status(Status::ToDo)->create(['description' => '<p>Start</p>']);

    $this->actingAs($user);

    $page = visit("/ABC-{$task->task_number}");
    $page->click('@edit-task')
        ->assertSee('Description')
        ->wait(1.5); // Let Livewire render the form and Alpine mount the editor.

    $result = $page->script(<<<'JS'
        (function () {
            const data = window.Alpine.$data(document.querySelector('[x-data="richEditor"]'));
            const img = () => new File([new Uint8Array([1, 2, 3])], 'x.png', { type: 'image/png' });

            return JSON.stringify({
                // Desktop: image arrives on .files.
                files: data.filesFrom({ files: [img()], items: [] }).length,
                // Mobile: image arrives only on .items and must be unwrapped.
                items: data.filesFrom({ files: [], items: [{ kind: 'file', type: 'image/png', getAsFile: img }] }).length,
                // Pasted text (a non-file item) must be ignored.
                text: data.filesFrom({ files: [], items: [{ kind: 'string', type: 'text/plain', getAsFile: () => null }] }).length,
                // A non-image file is not an embeddable image.
                pdf: data.filesFrom({ files: [new File([new Uint8Array([1])], 'a.pdf', { type: 'application/pdf' })], items: [] }).length,
            });
        })()
    JS);

    $extracted = json_decode($result, true);

    expect($extracted)->toMatchArray([
        'files' => 1,
        'items' => 1,
        'text' => 0,
        'pdf' => 0,
    ]);

    $page->assertNoJavascriptErrors();
});
