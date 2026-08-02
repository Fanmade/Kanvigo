<?php

namespace App\Support\Export;

/**
 * The links that let a reader walk a bundle as the tree it came from.
 *
 * A one-file-per-item archive otherwise only links where an author happened to
 * write a cross-reference, which leaves the structure to be guessed at from file
 * names. Each document therefore carries a way up to its parent and a way down
 * to what is nested under it — relative, and only to files that actually travel
 * in this archive.
 */
final readonly class ExportNavigation
{
    /**
     * @param  array{title: string, path: string}|null  $parent  the item this one sits under
     * @param  list<array{title: string, path: string}>  $children  the items directly below it
     */
    public function __construct(
        private ?array $parent = null,
        private array $children = [],
    ) {}

    /**
     * The navigation as Markdown lines, or nothing at all for a document with
     * neither a parent nor children in the archive.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        if ($this->parent !== null) {
            $lines[] = '*'.__('Up').': ['.$this->parent['title'].']('.$this->parent['path'].')*';
        }

        if ($this->children !== []) {
            $links = array_map(
                static fn (array $child): string => '['.$child['title'].']('.$child['path'].')',
                $this->children,
            );

            $lines[] = '*'.__('Below').': '.implode(' · ', $links).'*';
        }

        return $lines;
    }
}
