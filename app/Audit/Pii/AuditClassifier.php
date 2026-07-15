<?php

namespace App\Audit\Pii;

use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * The single source of truth for how sensitive each audit-event field is.
 *
 * Fields are addressed by their path in the event's array shape — ['actor_id'],
 * ['context', 'ip'], ['metadata', 'email']. A path is Public unless
 * config/audit.php classifies it otherwise, so a new metadata key is only ever
 * over-shared on purpose; an action may override the default classification of
 * a path, because the conventional "old"/"new" keys carry a task title in one
 * event and a free-text cancellation message in the next.
 *
 * Every sink reads this classification: a redacting transport sink maps it to a
 * {@see RedactionStrategy}, the Chronicle bridge maps it to per-subject field
 * encryption.
 */
class AuditClassifier
{
    public function classify(AuditEvent $event, string ...$path): DataClass
    {
        $segments = array_values($path);

        $classification = $this->lookup(config('audit.pii.actions.'.$event->action, []), $segments)
            ?? $this->lookup(config('audit.pii.fields', []), $segments);

        return $classification === null
            ? DataClass::Public
            : DataClass::from($classification);
    }

    public function strategyFor(DataClass $class): RedactionStrategy
    {
        $strategy = config('audit.pii.strategies.'.$class->value);

        return $strategy === null
            ? RedactionStrategy::Keep
            : RedactionStrategy::from($strategy);
    }

    /**
     * The strategy to apply to a field, resolved through its classification.
     */
    public function strategyForField(AuditEvent $event, string ...$path): RedactionStrategy
    {
        return $this->strategyFor($this->classify($event, ...$path));
    }

    /**
     * Walk a classification map segment by segment. Deliberately does not use
     * dot notation: a metadata key is free-form and may itself contain a dot.
     *
     * @param  array<string, mixed>  $map
     * @param  list<string>  $path
     */
    protected function lookup(array $map, array $path): ?string
    {
        $found = $map;

        foreach ($path as $segment) {
            if (! is_array($found) || ! array_key_exists($segment, $found)) {
                return null;
            }

            $found = $found[$segment];
        }

        return is_string($found) ? $found : null;
    }
}
