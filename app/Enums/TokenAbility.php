<?php

namespace App\Enums;

enum TokenAbility: string
{
    case Read = 'read';
    case Write = 'write';
    case Audit = 'audit';

    /**
     * The human-readable, translatable label for the ability.
     */
    public function label(): string
    {
        return match ($this) {
            self::Read => __('Read-only'),
            self::Write => __('Read and write'),
            self::Audit => __('Audit event stream'),
        };
    }

    /**
     * The set of ability values granted for the given access level.
     *
     * Write access implies read access. The audit stream is a separate,
     * privileged scope — it grants only itself, never the project read/write
     * abilities, so an audit-consumer token is least-privilege by construction.
     *
     * @return array<int, string>
     */
    public static function abilitiesFor(self $level): array
    {
        return match ($level) {
            self::Read => [self::Read->value],
            self::Write => [self::Read->value, self::Write->value],
            self::Audit => [self::Audit->value],
        };
    }
}
