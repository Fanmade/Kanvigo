<?php

namespace App\Enums;

/**
 * How a user who has switched e-mail on wants it delivered:
 *
 * - Immediate: one mail per notification, as it happens.
 * - Daily / Weekly: nothing as it happens, and one digest per interval
 *   collecting whatever is still unread.
 *
 * The mode only matters once the master e-mail switch is on; with it off,
 * nothing is sent either way.
 */
enum DeliveryMode: string
{
    case Immediate = 'immediate';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public static function default(): self
    {
        return self::Immediate;
    }

    /**
     * The human-readable, translatable label for the choice.
     */
    public function label(): string
    {
        return match ($this) {
            self::Immediate => __('As it happens'),
            self::Daily => __('Daily digest'),
            self::Weekly => __('Weekly digest'),
        };
    }

    /**
     * How long must pass before another digest is due. Immediate delivery has no
     * interval — it never produces a digest.
     */
    public function intervalDays(): ?int
    {
        return match ($this) {
            self::Immediate => null,
            self::Daily => 1,
            self::Weekly => 7,
        };
    }
}
