<?php

namespace App\Enums;

enum TokenAbility: string
{
    case Read = 'read';
    case Write = 'write';

    /**
     * The human-readable, translatable label for the ability.
     */
    public function label(): string
    {
        return match ($this) {
            self::Read => __('Read-only'),
            self::Write => __('Read and write'),
        };
    }

    /**
     * The set of ability values granted for the given access level.
     *
     * Write access implies read access.
     *
     * @return array<int, string>
     */
    public static function abilitiesFor(self $level): array
    {
        return match ($level) {
            self::Read => [self::Read->value],
            self::Write => [self::Read->value, self::Write->value],
        };
    }
}
