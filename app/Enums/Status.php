<?php

namespace App\Enums;

enum Status: string
{
    case Planned = 'Planned';
    case ToDo = 'ToDo';
    case InProgress = 'In progress';
    case Done = 'Done';

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
            self::Done => 'green',
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
        };
    }

    /**
     * The statuses in board-column order.
     *
     * @return array<int, self>
     */
    public static function columns(): array
    {
        return [self::Planned, self::ToDo, self::InProgress, self::Done];
    }
}
