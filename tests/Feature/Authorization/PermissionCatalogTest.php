<?php

use App\Authorization\PermissionCatalog;
use App\Authorization\ProjectRoleProvisioner;

it('has a label for every catalog permission', function () {
    foreach (ProjectRoleProvisioner::CATALOG as $permission) {
        expect(PermissionCatalog::LABELS)->toHaveKey($permission);
    }
});

it('has a German translation for every permission label', function () {
    $german = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_values(array_filter(
        PermissionCatalog::LABELS,
        static fn (string $label): bool => ! array_key_exists($label, $german),
    ));

    expect($missing)->toBe([], 'Missing German translations for permission labels: '.implode(', ', $missing));
});

it('falls back to a title-cased label for a permission outside the catalog', function () {
    expect(PermissionCatalog::label('some-unknown-permission'))->toBe('Some Unknown Permission');
});

it('only describes permissions that exist in the catalog', function () {
    expect(array_keys(PermissionCatalog::DESCRIPTIONS))
        ->each->toBeIn(ProjectRoleProvisioner::CATALOG);
});

it('has a German translation for every permission description', function () {
    $german = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_values(array_filter(
        PermissionCatalog::DESCRIPTIONS,
        static fn (string $description): bool => ! array_key_exists($description, $german),
    ));

    expect($missing)->toBe([], 'Missing German translations for permission descriptions: '.implode(', ', $missing));
});

it('returns null for a permission without a description', function () {
    expect(PermissionCatalog::description('some-unknown-permission'))->toBeNull();
});
