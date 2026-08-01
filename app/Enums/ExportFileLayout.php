<?php

namespace App\Enums;

/**
 * How a multi-file export arranges its files inside the archive.
 *
 * Neither shape is right for everyone: a flat archive is easy to skim and to
 * drop into another tool, while a nested one mirrors the board and reads like
 * the tree it came from. So it is a setting rather than a decision made here.
 */
enum ExportFileLayout: string
{
    /**
     * Every item at the archive root, named after its reference — the same names
     * the single-file export gives a download.
     */
    case Flat = 'flat';

    /**
     * A directory per item that has children, holding that item's own `index.md`
     * and the files of everything nested under it.
     */
    case Nested = 'nested';

    /**
     * The human-readable, translatable label for the layout.
     */
    public function label(): string
    {
        return match ($this) {
            self::Flat => __('All files side by side'),
            self::Nested => __('A folder per item with subtasks'),
        };
    }
}
