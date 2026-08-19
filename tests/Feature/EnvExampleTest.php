<?php

use Symfony\Component\Finder\Finder;

/**
 * `.env.example` is the only place an administrator learns that a setting
 * exists at all — the alternative is reading `config/`, which nobody does until
 * something is already wrong (KAN-567). Stock Laravel configs are excluded:
 * their surface is documented upstream, and listing every SQS and Memcached
 * variable would bury the handful that are actually ours.
 *
 * Paths are built from `__DIR__` rather than `config_path()`, because a dataset
 * is resolved before the application boots.
 *
 * @var list<string>
 */
$ownConfigFiles = [
    'admin.php',
    'attachments.php',
    'audit.php',
    'delegated-permissions.php',
    'fortify.php',
    'kanvigo.php',
    'mcp.php',
    'scramble.php',
];

/**
 * Every environment variable the given app-specific config file reads.
 *
 * @return list<string>
 */
function environmentVariablesIn(string $file): array
{
    $contents = (string) file_get_contents(dirname(__DIR__, 2).'/config/'.$file);

    preg_match_all("/env\('([A-Z0-9_]+)'/", $contents, $matches);

    return array_values(array_unique($matches[1]));
}

// A config that reads nothing — `mcp.php` today — would be a case with no
// assertions to make, so it only takes part in the coverage test below.
dataset('own configuration files', array_values(array_filter(
    $ownConfigFiles,
    static fn (string $file): bool => environmentVariablesIn($file) !== [],
)));

it('documents every setting its own config files read', function (string $file): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    foreach (environmentVariablesIn($file) as $variable) {
        // Commented out is fine — the point is that the name is discoverable.
        expect($example)->toMatch(
            '/^#?\s*'.preg_quote($variable, '/').'=/m',
            sprintf('config/%s reads %s, which .env.example never mentions.', $file, $variable),
        );
    }
})->with('own configuration files');

it('lists every config file that is not stock Laravel', function () use ($ownConfigFiles): void {
    $present = collect(Finder::create()->files()->in(dirname(__DIR__, 2).'/config')->name('*.php'))
        ->map(static fn ($file): string => $file->getFilename())
        ->values()
        ->all();

    // A new app-specific config has to be added to the list above, or its
    // settings would silently escape the check.
    expect($present)->toContain(...$ownConfigFiles);
});
