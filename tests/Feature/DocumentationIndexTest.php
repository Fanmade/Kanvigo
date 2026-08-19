<?php

use Symfony\Component\Finder\Finder;

/**
 * The docs index is hand-written, which is only sustainable while it cannot
 * silently rot: a page added without an entry (or an entry left behind after a
 * page moved) is invisible to anyone browsing `docs/`, and nobody notices until
 * they go looking for something that is documented but unreachable (KAN-16).
 */
$docsPath = dirname(__DIR__, 2).'/docs';

dataset('documentation pages', static function () use ($docsPath): array {
    $files = Finder::create()->files()->in($docsPath)->name('*.md')->notName('README.md');

    return collect($files)
        ->map(static fn ($file): string => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()))
        ->sort()
        ->values()
        ->all();
});

it('lists every documentation page in the index', function (string $page) use ($docsPath) {
    expect(file_get_contents($docsPath.'/README.md'))->toContain('('.$page.')');
})->with('documentation pages');

/**
 * The audience folders are the whole point of the split: a page directly in
 * `docs/` belongs to no audience, and is how the old flat folder creeps back.
 * `using/` is for the people working in Kanvigo, `administering/` for whoever
 * runs an instance and `developing/` for whoever works on or integrates with
 * it; `adr/` is the fourth home — a decision record documents a choice, not the
 * product.
 */
it('keeps every documentation page in a usage, administration, developer or decision home', function (string $page) {
    $homes = ['using/', 'administering/', 'developing/', 'adr/'];

    expect(array_filter($homes, static fn (string $home): bool => str_starts_with($page, $home)))->not->toBeEmpty();
})->with('documentation pages');

it('does not link pages from the index that no longer exist', function () use ($docsPath) {
    preg_match_all('/]\((?!https?:)([^)#]+\.md)\)/', file_get_contents($docsPath.'/README.md'), $matches);

    $linked = collect($matches[1])->reject(static fn (string $link): bool => str_starts_with($link, '../'));

    expect($linked)->not->toBeEmpty();

    foreach ($linked as $link) {
        expect($docsPath.'/'.$link)->toBeFile();
    }
});
