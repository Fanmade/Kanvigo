<?php

namespace App\Support;

/**
 * A single command palette entry: a matched project/task or an action.
 *
 * Decouples the palette view from Eloquent models so the search backend can
 * change without touching the rendering. An action either navigates to a {@see
 * $url} or, when {@see $event} is set, dispatches that Livewire event instead
 * (e.g. to open a dialog without leaving the page).
 *
 * {@see $deprioritized} sinks an entry to the bottom of the palette (e.g. a
 * completed or canceled task), so it never sits above the action a user is
 * reaching for. {@see $badge} marks an entry with a short at-a-glance qualifier
 * shown beside the reference — today only `draft`, for an unpublished doc. It is
 * a marker, not a label: the palette owns the wording, keeping this DTO free of
 * presentation (and of translation) just like {@see $icon}.
 */
readonly class SearchResult
{
    public function __construct(
        public string $type,
        public string $title,
        public string $icon,
        public string $url = '',
        public ?string $reference = null,
        public bool $pinned = false,
        public ?TaskProgress $progress = null,
        public ?string $event = null,
        public bool $deprioritized = false,
        public ?string $badge = null,
    ) {}
}
