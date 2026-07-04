<?php

namespace Tests\Support\Audit;

use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * A second, independent recording sink (its own static store), so tests can
 * assert that every registered sink receives an accepted event.
 */
class SecondRecordingSink extends RecordingSink
{
    /** @var list<AuditEvent> */
    public static array $received = [];
}
