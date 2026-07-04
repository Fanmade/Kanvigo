<?php

namespace Tests\Support\Audit;

use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use Kanvigo\Audit\Contracts\SinkPolicy;
use RuntimeException;

/**
 * A queued fail-open sink, shipped by the outbox drain command; flipping
 * $failing simulates a temporarily unavailable transport.
 */
class QueuedRecordingSink implements AuditSink
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
            throw new RuntimeException('The transport is unavailable.');
        }

        static::$received[] = $event;
    }

    public function policy(): SinkPolicy
    {
        return SinkPolicy::queued();
    }
}
