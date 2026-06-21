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

`composer test:browser` reaps the leftover server (`pkill -f 'playwright run-server'`)
after the run while preserving the exit code, so the command always returns promptly.
If browser tests ever do hang, the cause is almost always orphaned `playwright
run-server` processes — `pkill -f 'playwright run-server'` clears them.
