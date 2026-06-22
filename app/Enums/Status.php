<?php

namespace App\Enums;

enum Status: string
{
    case Planned = 'Planned';
    case ToDo = 'ToDo';
    case InProgress = 'In progress';
    case Done = 'Done';
    case Canceled = 'Canceled';

    /**
     * The human-readable, translatable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planned'),
            self::ToDo => __('To do'),
            self::InProgress => __('In progress'),
            self::Done => __('Done'),
            self::Canceled => __('Canceled'),
        };
    }

    /**
     * The Flux badge/accent color for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Planned => 'zinc',
            self::ToDo => 'sky',
            self::InProgress => 'amber',
            self::Done => 'teal',
            self::Canceled => 'red',
        };
    }

    /**
     * The Heroicon name representing this status.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Planned => 'inbox',
            self::ToDo => 'list-bullet',
            self::InProgress => 'arrow-path',
            self::Done => 'check-circle',
            self::Canceled => 'x-circle',
        };
    }

    /**
     * The statuses in board-column order. The terminal "Canceled" state is not a
     * working column, so it is intentionally excluded.
     *
     * @return array<int, self>
     */
    public static function columns(): array
    {
        return [self::Planned, self::ToDo, self::InProgress, self::Done];
    }

    /**
     * Whether this is a terminal state — the work is finished and no longer
     * actively progressing (either completed or abandoned).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Canceled => true,
            default => false,
        };
    }
}
