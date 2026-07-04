<?php

use App\Audit\Sinks\ActivityLogSink;

return [

    /*
    |--------------------------------------------------------------------------
    | Audit Sinks
    |--------------------------------------------------------------------------
    |
    | Every event emitted through Audit::record() is offered to each sink
    | listed here (Kanvigo\Audit\Contracts\AuditSink implementations); a sink
    | receives the events its accepts() filter wants, delivered according to
    | its policy(). The default ActivityLogSink is the product activity feed —
    | it accepts only the feed-worthy content events and reproduces the
    | classic activity-log behavior. Self-hosters add compliance or transport
    | sinks (e.g. the Chronicle bridge) by appending their class here.
    |
    */

    'sinks' => [
        ActivityLogSink::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox Retention
    |--------------------------------------------------------------------------
    |
    | Dispatched outbox rows (nothing left to drain) are deleted by the daily
    | audit:outbox:prune command once they are older than this many days.
    | Set to 0 to keep the outbox forever.
    |
    */

    'outbox' => [
        'retention_days' => (int) env('AUDIT_OUTBOX_RETENTION_DAYS', 30),
    ],

];
