<?php

namespace App\Support;

/**
 * A single command palette entry: a matched project/story/task or an action.
 *
 * Decouples the palette view from Eloquent models so the search backend can
 * change without touching the rendering.
 */
readonly class SearchResult
{
    public function __construct(
        public string $type,
        public string $title,
        public string $url,
        public string $icon,
        public ?string $reference = null,
        public bool $pinned = false,
    ) {}
}
