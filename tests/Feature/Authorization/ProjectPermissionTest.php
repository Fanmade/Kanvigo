<?php

use App\Authorization\ProjectPermission;

/**
 * The catalog itself cannot drift — the flat and grouped forms are both derived
 * from the cases, and PHP's exhaustive `match` forces every case to carry a
 * group and its labels. What is left to guard is the German coverage and the
 * documented fallback for names outside the catalog (custom roles rely on it).
 */
it('has a German translation for every permission label', function () {
    $german = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_values(array_filter(
        array_map(static fn (ProjectPermission $permission): string => $permission->label(), ProjectPermission::cases()),
        static fn (string $label): bool => ! array_key_exists($label, $german),
    ));

    expect($missing)->toBe([], 'Missing German translations for permission labels: '.implode(', ', $missing));
});

it('has a German translation for every picker label', function () {
    $german = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_values(array_filter(
        array_map(static fn (ProjectPermission $permission): string => $permission->pickerLabel(), ProjectPermission::cases()),
        static fn (string $label): bool => ! array_key_exists($label, $german),
    ));

    expect($missing)->toBe([], 'Missing German translations for picker labels: '.implode(', ', $missing));
});

it('has a German translation for every permission description', function () {
    $german = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_values(array_filter(
        array_filter(array_map(static fn (ProjectPermission $permission): ?string => $permission->description(), ProjectPermission::cases())),
        static fn (string $description): bool => ! array_key_exists($description, $german),
    ));

    expect($missing)->toBe([], 'Missing German translations for permission descriptions: '.implode(', ', $missing));
});

it('groups every catalog permission exactly once', function () {
    expect(collect(ProjectPermission::groups())->flatten()->sort()->values()->all())
        ->toBe(collect(ProjectPermission::names())->sort()->values()->all());
});

it('falls back to a title-cased label for a permission outside the catalog', function () {
    expect(ProjectPermission::labelFor('some-unknown-permission'))->toBe('Some Unknown Permission')
        ->and(ProjectPermission::pickerLabelFor('some-unknown-permission'))->toBe('Some Unknown Permission');
});

it('returns null for a permission without a description', function () {
    expect(ProjectPermission::descriptionFor('some-unknown-permission'))->toBeNull()
        ->and(ProjectPermission::descriptionFor('view-project'))->toBeNull();
});
