<?php

/**
 * `composer setup` is the whole first-run path, and its steps are order- and
 * guard-sensitive in ways nothing else catches: the SQLite file has to exist
 * before `migrate` runs, or a fresh `git clone` install fails at the first
 * command a new contributor types (KAN-564). Nothing else in the suite executes
 * composer scripts, so these assertions stand in for that.
 */
$setupSteps = static function (): array {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    return $composer['scripts']['setup'];
};

it('creates the database file before migrating', function () use ($setupSteps): void {
    $steps = $setupSteps();

    $createsDatabase = array_keys(array_filter(
        $steps,
        static fn (string $step): bool => str_contains($step, 'database/database.sqlite'),
    ));

    $migrates = array_keys(array_filter(
        $steps,
        static fn (string $step): bool => str_contains($step, 'artisan migrate'),
    ));

    expect($createsDatabase)->not->toBeEmpty('The setup script never creates the SQLite file.')
        ->and($migrates)->not->toBeEmpty()
        ->and(min($createsDatabase))->toBeLessThan(min($migrates));
});

it('only creates the database file for a sqlite connection', function () use ($setupSteps): void {
    $step = collect($setupSteps())
        ->first(static fn (string $step): bool => str_contains($step, 'database/database.sqlite'));

    // Someone on Postgres or MySQL should not end up with a stray empty file.
    expect($step)->toContain('DB_CONNECTION')
        ->and($step)->toContain('sqlite');
});

it('does not seed, which would duplicate the non-idempotent demo content', function () use ($setupSteps): void {
    expect($setupSteps())->each->not->toContain('db:seed');
});
