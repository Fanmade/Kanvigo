<?php

namespace Tests\Support\Audit;

use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use Kanvigo\Audit\Contracts\SinkPolicy;
use RuntimeException;

/**
 * A sync fail-open sink whose record() always throws — for asserting the
 * manager isolates fail-open failures.
 */
class FailingSink implements AuditSink
{
    public function accepts(AuditEvent $event): bool
    {
        return true;
    }

    public function record(AuditEvent $event): void
    {
        throw new RuntimeException('This audit sink is broken.');
    }

    public function policy(): SinkPolicy
    {
        return SinkPolicy::sync();
    }
}
