<?php

namespace Tests\Support\Audit;

use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * A recording sink that accepts nothing — for asserting the manager honors
 * a sink's accepts() filter.
 */
class RejectingSink extends RecordingSink
{
    /** @var list<AuditEvent> */
    public static array $received = [];

    public function accepts(AuditEvent $event): bool
    {
        return false;
    }
}
