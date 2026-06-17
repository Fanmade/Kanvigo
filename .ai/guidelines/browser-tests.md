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
