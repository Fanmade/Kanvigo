<?php

use App\Models\Project;
use App\Models\User;

it('warns when a dropped file is larger than the upload limit', function () {
    // The test is about the size *check*, not about moving megabytes: with the
    // real 12 MB limit it has to allocate 13 MB in the browser, and under a
    // parallel suite that stall alone can outlive the toast it is waiting for.
    // A small limit exercises the same branch for a few kilobytes.
    config()->set('attachments.max_size', 16);

    $user = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit('/'.$project->short_name);

    // Synthesize a drop of a file that exceeds the configured limit. The
    // browser can't pick an oversized file through a real file dialog, so we
    // build the File and DataTransfer by hand and fire the drop event that the
    // dropzone's Alpine handler listens for.
    //
    // Everything after the drop is client-side, so an event dispatched before
    // Alpine has bound the dropzone lands on nothing at all and the test waits
    // out its timeout for a toast that was never raised. Wait for Alpine to have
    // initialised the element (it stamps `_x_dataStack` on a root it owns)
    // instead of assuming the page is ready the moment it has loaded.
    $page->script(<<<'JS'
        (() => new Promise((resolve, reject) => {
            const deadline = Date.now() + 10000;

            const attempt = () => {
                const dropzone = document.querySelector('[data-test="description-dropzone"]');

                if (! dropzone?._x_dataStack) {
                    if (Date.now() > deadline) {
                        reject(new Error('The dropzone was never initialised by Alpine.'));

                        return;
                    }

                    setTimeout(attempt, 50);

                    return;
                }

                const file = new File([new Uint8Array(32 * 1024)], 'huge.pdf', { type: 'application/pdf' });
                const transfer = new DataTransfer();
                transfer.items.add(file);

                const event = new Event('drop', { bubbles: true, cancelable: true });
                Object.defineProperty(event, 'dataTransfer', { value: transfer });
                dropzone.dispatchEvent(event);

                resolve('dropped');
            };

            attempt();
        }))()
    JS);

    $page->waitForText('huge.pdf is too large')
        ->assertSee('The maximum file size is 16 KB')
        ->assertNoJavascriptErrors();
});
