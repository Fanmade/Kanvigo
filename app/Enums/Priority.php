<?php

namespace App\Enums;

use App\Enums\Concerns\HasCaseNames;

enum Priority: int
{
    use HasCaseNames;

    case Lowest = 1;
    case Low = 2;
    case Medium = 3;
    case High = 4;
    case Highest = 5;

    /**
     * The default priority applied when none is chosen (the middle level).
     */
    public static function default(): self
    {
        return self::Medium;
    }

    /**
     * The human-readable, translatable label for the priority.
     */
    public function label(): string
    {
        return match ($this) {
            self::Lowest => __('Lowest'),
            self::Low => __('Low'),
            self::Medium => __('Medium'),
            self::High => __('High'),
            self::Highest => __('Highest'),
        };
    }

    /**
     * The Flux badge/accent color for this priority.
     */
    public function color(): string
    {
        return match ($this) {
            self::Lowest => 'zinc',
            self::Low => 'sky',
            self::Medium => 'amber',
            self::High => 'orange',
            self::Highest => 'red',
        };
    }

    /**
     * The Heroicon name representing this priority.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Lowest => 'chevron-double-down',
            self::Low => 'chevron-down',
            self::Medium => 'minus',
            self::High => 'chevron-up',
            self::Highest => 'chevron-double-up',
        };
    }

    /**
     * The priorities ranked from highest to lowest — the order they should appear
     * in pickers and filters, so the most urgent choice sits at the top.
     *
     * @return array<int, self>
     */
    public static function descending(): array
    {
        return [self::Highest, self::High, self::Medium, self::Low, self::Lowest];
    }
}
