<?php

namespace Tests\Support\Audit;

use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use Kanvigo\Audit\Contracts\SinkPolicy;

/**
 * A sync fail-open sink that accepts everything and remembers what it got.
 */
class RecordingSink implements AuditSink
{
    /** @var list<AuditEvent> */
    public static array $received = [];

    public static function reset(): void
    {
        static::$received = [];
    }

    public function accepts(AuditEvent $event): bool
    {
        return true;
    }

    public function record(AuditEvent $event): void
    {
        static::$received[] = $event;
    }

    public function policy(): SinkPolicy
    {
        return SinkPolicy::sync();
    }
}
