<?php

use App\Models\Project;
use App\Models\User;

it('warns when a dropped file is larger than the upload limit', function () {
    // The test is about the size *check*, not about moving megabytes: with the
    // real 12 MB limit it has to allocate 13 MB in the browser, which is weight
    // that proves nothing. A small limit exercises the same branch.
    config()->set('attachments.max_size', 16);

    $user = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit('/'.$project->short_name);

    // Synthesize a drop of a file that exceeds the configured limit: the browser
    // cannot pick an oversized file through a real file dialog, so the File and
    // DataTransfer are built by hand and the drop event is fired at the
    // dropzone's Alpine handler.
    //
    // Everything after the drop is client-side, and a synthetic event gets none
    // of the auto-waiting a real click would. Dispatched a moment early — before
    // Alpine binds the dropzone, or before Flux registers the `$flux` magic the
    // handler raises its toast through — it is simply lost, and the assertion
    // then waits out its timeout for a warning that was never raised. So the
    // drop is repeated until the warning shows, and if it never does the script
    // reports what it found rather than leaving a bare timeout to interpret.
    $page->script(<<<'JS'
        (() => new Promise((resolve, reject) => {
            const deadline = Date.now() + 10000;
            const warned = () => document.body.innerText.includes('is too large');

            const drop = (dropzone) => {
                const file = new File([new Uint8Array(32 * 1024)], 'huge.pdf', { type: 'application/pdf' });
                const transfer = new DataTransfer();
                transfer.items.add(file);

                const event = new Event('drop', { bubbles: true, cancelable: true });
                Object.defineProperty(event, 'dataTransfer', { value: transfer });
                dropzone.dispatchEvent(event);
            };

            const attempt = () => {
                if (warned()) {
                    resolve('warned');

                    return;
                }

                const dropzone = document.querySelector('[data-test="description-dropzone"]');

                if (Date.now() > deadline) {
                    reject(new Error(JSON.stringify({
                        reason: 'the dropzone never warned about the oversized file',
                        dropzoneFound: dropzone !== null,
                        alpineReady: Boolean(dropzone?._x_dataStack),
                        alpineStarted: Boolean(window.Alpine),
                        maxBytes: dropzone?._x_dataStack?.[0]?.maxBytes ?? null,
                    })));

                    return;
                }

                if (dropzone?._x_dataStack) {
                    drop(dropzone);
                }

                setTimeout(attempt, 250);
            };

            attempt();
        }))()
    JS);

    $page->waitForText('huge.pdf is too large')
        ->assertSee('The maximum file size is 16 KB')
        ->assertNoJavascriptErrors();
});

it('splits a multi-file drop into upload batches no larger than the per-file limit', function () {
    config()->set('attachments.max_size', 16);

    $user = User::factory()->create();
    $project = Project::factory()->create();
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit('/'.$project->short_name);

    // The batching itself is pure client-side logic on the dropzone's Alpine
    // component, so it is exercised directly: hand-built Files go in, the
    // resulting batches come out as name/size shapes to assert on. The same
    // retry loop as above waits for Alpine to bind the dropzone first.
    $result = $page->script(<<<'JS'
        (() => new Promise((resolve, reject) => {
            const deadline = Date.now() + 10000;

            const file = (name, kb) => new File([new Uint8Array(kb * 1024)], name, { type: 'application/pdf' });

            const attempt = () => {
                const dropzone = document.querySelector('[data-test="description-dropzone"]');
                const component = dropzone?._x_dataStack?.[0];

                if (component?.batches) {
                    resolve(component.batches([
                        file('a.pdf', 10),
                        file('b.pdf', 10),
                        file('c.pdf', 10),
                        file('d.pdf', 4),
                    ]).map((batch) => batch.map((f) => f.name)));

                    return;
                }

                if (Date.now() > deadline) {
                    reject(new Error(JSON.stringify({
                        reason: 'the dropzone never exposed a batches() method',
                        dropzoneFound: dropzone !== null,
                        alpineReady: Boolean(component),
                    })));

                    return;
                }

                setTimeout(attempt, 250);
            };

            attempt();
        }))()
    JS);

    // Greedy packing against the 16 KB cap: 10+10 exceeds it, so each 10 KB
    // file opens its own batch and the 4 KB file joins the last one. Order is
    // preserved and no file is dropped.
    expect($result)->toBe([['a.pdf'], ['b.pdf'], ['c.pdf', 'd.pdf']]);

    $page->assertNoJavascriptErrors();
});
