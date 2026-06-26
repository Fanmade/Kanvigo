<?php

/**
 * Guards against a Flux select rendering its empty entry twice.
 *
 * A native `<flux:select>` (the default variant) automatically renders its own
 * `<option>` for the `placeholder` prop. Adding an explicit
 * `<flux:select.option value="">` on top of that shows the empty entry twice —
 * e.g. two "No type" rows (KAN-293 follow-up). Use one or the other:
 *
 *   - a `placeholder` when empty is NOT a valid choice (a prompt to pick), or
 *   - an explicit empty option when empty IS a selectable value ("No type").
 *
 * Listbox/combobox variants render the placeholder as trigger text, not an
 * option, so they may legitimately carry both and are exempt.
 */

/**
 * Every Blade template in the app.
 *
 * @return array<int, string>
 */
function bladeTemplates(): array
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    $paths = [];

    foreach ($files as $file) {
        if (preg_match('/\.blade\.php$/', $file->getPathname())) {
            $paths[] = $file->getPathname();
        }
    }

    return $paths;
}

it('never combines a placeholder with an explicit empty option on a native select', function () {
    $violations = [];

    foreach (bladeTemplates() as $path) {
        $source = file_get_contents($path);

        // Match each <flux:select ...> ... </flux:select> block (not .option).
        preg_match_all('/<flux:select(?=[\s>])(.*?)>(.*?)<\/flux:select>/s', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $attributes, $body]) {
            // Custom variants show the placeholder as trigger text, never as an
            // option, so they can carry both safely.
            if (preg_match('/variant=["\'](?:listbox|combobox)/', $attributes)) {
                continue;
            }

            $hasPlaceholder = (bool) preg_match('/[\s:]placeholder=/', $attributes);
            $hasEmptyOption = (bool) preg_match('/value=(["\'])\1/', $body);

            if ($hasPlaceholder && $hasEmptyOption) {
                $violations[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($violations)->toBe(
        [],
        'These native flux:select fields render the empty entry twice (drop the '
        .'placeholder or the explicit value="" option):'.PHP_EOL.'  - '
        .implode(PHP_EOL.'  - ', array_unique($violations)),
    );
});
