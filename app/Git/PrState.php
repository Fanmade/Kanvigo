<?php

namespace App\Git;

use App\Enums\Status;

/**
 * The state of the pull request linked to a task. Mirrors the shape of
 * {@see Status} (a string-backed enum with a translatable
 * {@see label()} and a Flux {@see color()}). "None" is the initial state of a
 * link that has reserved a branch but has no PR open yet.
 */
enum PrState: string
{
    case None = 'None';
    case Open = 'Open';
    case Merged = 'Merged';
    case Closed = 'Closed';

    /**
     * The human-readable, translatable label for the PR state.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => __('No PR'),
            self::Open => __('Open'),
            self::Merged => __('Merged'),
            self::Closed => __('Closed'),
        };
    }

    /**
     * The Flux badge/accent color for this PR state, following the colors a
     * forge conventionally uses: green open, purple merged, red closed.
     */
    public function color(): string
    {
        return match ($this) {
            self::None => 'zinc',
            self::Open => 'green',
            self::Merged => 'purple',
            self::Closed => 'red',
        };
    }
}
