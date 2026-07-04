<?php

namespace Tests\Support\Audit;

use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use Kanvigo\Audit\Contracts\SinkPolicy;
use RuntimeException;

/**
 * A fail-closed compliance-style sink: runs pre-commit inside the domain
 * transaction; flipping $failing simulates its write failing, which must
 * abort the audited action.
 */
class FailClosedSink implements AuditSink
{
    public static bool $failing = false;

    /** @var list<AuditEvent> */
    public static array $received = [];

    public static function reset(): void
    {
        static::$failing = false;
        static::$received = [];
    }

    public function accepts(AuditEvent $event): bool
    {
        return true;
    }

    public function record(AuditEvent $event): void
    {
        if (static::$failing) {
            throw new RuntimeException('The compliance ledger is unavailable.');
        }

        static::$received[] = $event;
    }

    public function policy(): SinkPolicy
    {
        return SinkPolicy::failClosed();
    }
}
