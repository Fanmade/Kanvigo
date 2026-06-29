<?php

namespace App\Enums\Concerns;

use UnitEnum;

/**
 * Case-name helpers for enums whose API speaks in case names (e.g. "High",
 * "WontFix") rather than backing values: the list of names and a lookup from a
 * name back to its case.
 *
 * @phpstan-require-implements UnitEnum
 */
trait HasCaseNames
{
    /**
     * The names of every case, in declaration order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->name, self::cases());
    }

    /**
     * The case with the given name, or null when none matches.
     */
    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
