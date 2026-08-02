<?php

use App\Support\IconCatalog;

it('discovers every icon Flux can render, including app-published ones', function () {
    $icons = IconCatalog::available();

    expect($icons)
        ->toContain('tag')                 // bundled with Flux
        ->toContain('folder-git-2')        // published into resources/views/flux/icon
        ->and(count($icons))->toBeGreaterThan(300)
        ->and($icons)->toBe(array_values(array_unique($icons)))
        ->and($icons)->toBe(collect($icons)->sort()->values()->all());
});

it('filters the catalog by name and caps the result', function () {
    $matches = IconCatalog::search('arrow-down');

    expect($matches)->toContain('arrow-down')
        ->and($matches)->not->toContain('tag')
        ->and(IconCatalog::search('bug'))->toContain('bug-ant')
        ->and(IconCatalog::search('surely-not-an-icon'))->toBe([])
        ->and(IconCatalog::search('', limit: 5))->toHaveCount(5);
});

it('offers the top of the catalog when nothing is typed', function () {
    expect(IconCatalog::search(null, limit: 3))
        ->toBe(array_slice(IconCatalog::available(), 0, 3));
});

it('keeps a known icon and rejects an unknown or null one', function () {
    expect(IconCatalog::validOrNull('tag'))->toBe('tag')
        ->and(IconCatalog::validOrNull('not-a-real-icon'))->toBeNull()
        ->and(IconCatalog::validOrNull(null))->toBeNull();
});
