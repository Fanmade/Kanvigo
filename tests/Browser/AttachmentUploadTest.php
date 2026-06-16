<?php

use App\Models\Project;
use App\Models\User;

it('warns when a dropped file is larger than the upload limit', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);

    $this->actingAs($user);

    $page = visit('/'.$project->short_name);

    // Synthesize a drop of a file that exceeds the 12 MB default limit. The
    // browser can't pick an oversized file through a real file dialog, so we
    // build the File and DataTransfer by hand and fire the drop event that the
    // dropzone's Alpine handler listens for.
    $page->script(<<<'JS'
        const file = new File([new Uint8Array(13 * 1024 * 1024)], 'huge.pdf', { type: 'application/pdf' });
        const transfer = new DataTransfer();
        transfer.items.add(file);

        const dropzone = document.querySelector('[data-test="description-dropzone"]');
        const event = new Event('drop', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'dataTransfer', { value: transfer });
        dropzone.dispatchEvent(event);
    JS);

    $page->waitForText('huge.pdf is too large')
        ->assertSee('The maximum file size is 12 MB')
        ->assertNoJavascriptErrors();
});
