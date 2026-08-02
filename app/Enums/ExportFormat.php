<?php

namespace App\Enums;

/**
 * What an export is written as.
 *
 * Both formats say the same thing about an item and differ only in what reads
 * them: Markdown travels into another tool, HTML opens in a browser and looks
 * like a document. PDF and Word are a separate matter — they need an engine and
 * a page design, and live in their own epic.
 */
enum ExportFormat: string
{
    case Markdown = 'markdown';

    case Html = 'html';

    /**
     * The human-readable, translatable label for the format.
     */
    public function label(): string
    {
        return match ($this) {
            self::Markdown => __('Markdown'),
            self::Html => __('HTML page'),
        };
    }

    /**
     * The filename extension, without the dot.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Markdown => 'md',
            self::Html => 'html',
        };
    }

    /**
     * The content type a download is served with.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Markdown => 'text/markdown; charset=UTF-8',
            self::Html => 'text/html; charset=UTF-8',
        };
    }
}
