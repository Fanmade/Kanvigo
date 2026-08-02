# Browser Tests

Conventions for Pest browser tests (`tests/Browser/`, the `visit()` API). These
prevent the most common avoidable failures.

## Select by data attribute, never by visible text

Target elements with a `data-test` attribute and Pest's `@` selector — **not** the
visible label. Pest resolves `@create-project` to `[data-testid=create-project],
[data-test=create-project]`.

```blade
<flux:button wire:click="$set('showCreate', true)" data-test="create-project">
    {{ __('New project') }}
</flux:button>
```

```php
$page->click('@create-project')
    ->fill('@project-title', 'My Cool Project')
    ->assertValue('@project-short-name', 'MCP');
```

Why: a visible label like "New project" is rarely unique — the same text can appear
in a page button, the command palette, the sidebar, or a heading. `click('New
project')` then resolves to the wrong (often hidden) element and times out instead
of failing clearly. Data attributes are unambiguous and survive copy edits and
translation. Add a `data-test` to any element a test interacts with or asserts on.

## Assert on data-test selectors, not visible text

The same reasoning applies to assertions. Assert an element's presence with
`assertVisible`/`assertMissing` against a `@selector` — **not** `assertSee`/
`assertDontSee` of a text string.

```php
// Brittle — passes if the words appear anywhere; breaks on copy edits & translation
$page->assertSee('Cancel task');

// Robust — targets one specific element by its data-test attribute
$page->assertVisible('@cancel-task-button');
$page->click('@cancel-task-button')
    ->assertMissing('@cancel-task-button');
```

Why: `assertSee('Cancel task')` passes whenever those words appear anywhere on the
page — a tooltip, an unrelated heading, the document title — so it can pass while the
real control is absent, and fails the moment a label is reworded or translated.
`assertVisible('@cancel-task-button')` asserts that one specific element is present
and visible, regardless of its text. Use `assertSeeIn('@selector', $text)` only when
the rendered text content itself is what you're verifying (a count, a user-entered
value) — scope it to the element rather than the whole page.

## `screenshot()` takes a boolean first, filename second

The signature is `screenshot(bool $fullPage = true, ?string $filename = null)`. The
**first** argument is `$fullPage`, not the filename — passing a filename first throws
a `TypeError`. Images are written to `tests/Browser/Screenshots/`.

```php
$page->screenshot();                         // full page, auto-named after the test
$page->screenshot(false, 'command-palette'); // viewport only, custom name
```

## Always assert no JS errors

End interactive browser tests with `->assertNoJavascriptErrors()` so silent
client-side failures surface.

## Run browser tests via the composer script, never raw artisan

Always run `composer test:browser` (or `composer test:all` / `composer check`) —
**not** a bare `php artisan test --testsuite=Browser`.

Pest's browser plugin starts a `playwright run-server` background process and, under
`--parallel`, does not reliably reap it on teardown (and never reaps it if the test
process is killed). The leaked server keeps the command's stdout/stderr pipe open.
An interactive shell returns its prompt anyway, so a human sees the run finish in
seconds — but a non-interactive runner (an agent's shell, CI) blocks on that open
pipe until it times out, so the tests appear to "hang forever". Leaked servers also
pile up and starve the machine, slowing *all* later runs.

`composer test:browser` reaps the leftover server after the run (preserving the test
exit code), so the command always returns promptly. The reaper is deliberately
`pgrep -f 'mode launchServer' | grep -vx "$$" | xargs -r kill`, **not** a plain
`pkill -f 'playwright run-server'`: a bare `pkill` also matches composer's own
`sh -c` script shell — whose argv contains the search pattern — and SIGTERMs it, so
the script exits 143. Don't "simplify" it back to `pkill`.

If browser tests ever do hang, the cause is almost always orphaned
`playwright run-server` processes. Clear them with the same self-excluding command:
`pgrep -f 'mode launchServer' | grep -vx "$$" | xargs -r kill`. Any cleanup command
must not itself contain the search literal, or it kills its own shell.

The script also deletes `vendor/pestphp/pest-plugin-browser/.temp/playwright-server.json`
after reaping. That file holds the host and port of the **one** Playwright server the
whole suite shares: the parent process starts the server and writes the file, and each
parallel worker reads it and connects. Reaping the server without removing the file
leaves the next run's workers pointing at a dead port, which fails the run wholesale
with dozens of `file_get_contents(... playwright-server.json)` errors rather than one
clear message. Two suite runs back to back reproduce it every time; clearing the file
between runs is 3-for-3 green.

## Keep the worker count below the core count

`composer test:browser` pins `--processes=4` rather than letting paratest default to one
worker per core. Every worker drives the *same* Playwright server, so past a handful of
them the server — not the CPU — is the bottleneck, and individual actions start
overshooting the 15s ceiling set in `tests/Pest.php`. Measured on an 8-core box: 8
workers finish in ~26s, 4 workers in ~29s. Three seconds buys back the headroom that
turns "occasionally red" into "green", so don't raise it to chase the last few seconds.

## Wait for Alpine before dispatching a synthetic event

A test that fires an event itself — `script()` with `dispatchEvent`, rather than a
real `click()` — has no auto-waiting behind it. Dispatched straight after `visit()`,
the event can land on an element whose Alpine listeners are not bound yet, and it is
simply lost: nothing happens and the following assertion waits out its timeout for a
result that was never produced. Alpine stamps `_x_dataStack` on a root it owns, so
poll for that before dispatching (see `AttachmentUploadTest`):

```php
$page->script(<<<'JS'
    (() => new Promise((resolve, reject) => {
        const deadline = Date.now() + 10000;
        const attempt = () => {
            const el = document.querySelector('[data-test="description-dropzone"]');
            if (! el?._x_dataStack) {
                if (Date.now() > deadline) { reject(new Error('never initialised')); return; }
                setTimeout(attempt, 50);
                return;
            }
            el.dispatchEvent(/* ... */);
            resolve('dropped');
        };
        attempt();
    }))()
JS);
```

Give the poll its own deadline and reject with a message: "the element never
initialised" is a diagnosis, while a bare assertion timeout is a mystery.

## Put a barrier after opening a dialog

`click('@new-task')->click('@create-task-add-tag')` races the dialog's own opening.
Assert the second target is visible first — `->assertVisible('@create-task-add-tag')` —
so the click waits for a settled dialog instead of retrying against one that is still
arriving. The same applies after any Livewire round-trip that swaps markup.
