<?php

/**
 * Guards against shipping UI strings that have no German translation.
 *
 * Scans the codebase for literal `__('…')` calls and asserts every key has an
 * entry in lang/de.json. Dynamic keys (`__($variable)`) cannot be checked
 * statically and are skipped — only string literals are verified.
 */

/**
 * Extract every literal translation key passed to `__()` across the app.
 *
 * @return array<int, string>
 */
function usedTranslationKeys(): array
{
    $directories = ['app', 'resources', 'routes', 'config'];
    $keys = [];

    foreach ($directories as $directory) {
        $path = base_path($directory);

        if (! is_dir($path)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! preg_match('/\.php$/', $file->getPathname())) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (preg_match_all('/__\(\s*([\x27\x22])(.*?)(?<!\\\\)\1/s', $source, $matches)) {
                foreach ($matches[2] as $key) {
                    $keys[stripcslashes($key)] = true;
                }
            }
        }
    }

    return array_keys($keys);
}

it('has a German translation for every literal __() string', function () {
    $german = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_values(array_filter(
        usedTranslationKeys(),
        static fn (string $key): bool => ! array_key_exists($key, $german),
    ));

    sort($missing);

    expect($missing)->toBe([], 'Missing German translations in lang/de.json:'.PHP_EOL.'  - '.implode(PHP_EOL.'  - ', $missing));
});
