<?php

namespace App\Enums;

/**
 * Which side of a wait the "Waiting on" page is showing:
 *
 * - OnMe: tasks somebody is waiting on *me* to answer — my queue.
 * - ByMe: tasks *I* am waiting on somebody else for — the asker's nag list.
 */
enum WaitingScope: string
{
    case OnMe = 'on-me';
    case ByMe = 'by-me';

    /**
     * The human-readable, translatable tab label.
     */
    public function label(): string
    {
        return match ($this) {
            self::OnMe => __('Waiting on me'),
            self::ByMe => __("I'm waiting on"),
        };
    }
}
