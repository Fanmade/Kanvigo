<?php

namespace App\Enums;

use App\Enums\Concerns\HasCaseNames;

/**
 * Why a task was canceled. Captured alongside the terminal {@see Status::Canceled}
 * state so an abandoned task stays on the record with an explanation, instead of
 * being deleted or silently closed.
 */
enum CancelReason: string
{
    use HasCaseNames;

    case WontFix = 'wont_fix';
    case Duplicate = 'duplicate';
    case Deprecated = 'deprecated';

    /**
     * The human-readable, translatable label for the reason.
     */
    public function label(): string
    {
        return match ($this) {
            self::WontFix => __('Won\'t fix'),
            self::Duplicate => __('Duplicate'),
            self::Deprecated => __('Deprecated'),
        };
    }

    /**
     * The Flux badge/accent color for this reason.
     */
    public function color(): string
    {
        return match ($this) {
            self::WontFix => 'zinc',
            self::Duplicate => 'sky',
            self::Deprecated => 'amber',
        };
    }

    /**
     * The Heroicon name representing this reason.
     */
    public function icon(): string
    {
        return match ($this) {
            self::WontFix => 'no-symbol',
            self::Duplicate => 'document-duplicate',
            self::Deprecated => 'archive-box-x-mark',
        };
    }
}
