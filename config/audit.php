<?php

use App\Audit\Pii\DataClass;
use App\Audit\Pii\RedactionStrategy;
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
    | An entry is either a class string, or a class => options pair. A sink that
    | ships events out of the system declares 'redact' => true and then only
    | ever sees the redacted copy of an event (see the classification below):
    |
    |     WebhookSink::class => ['redact' => true],
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

    /*
    |--------------------------------------------------------------------------
    | PII Classification & Redaction
    |--------------------------------------------------------------------------
    |
    | How sensitive each field of an audit event is, and what happens to it when
    | the event crosses an external boundary. This is the one classification
    | every sink reads: a redacting transport sink turns it into the strategies
    | below, the Chronicle bridge turns it into per-subject field encryption.
    |
    | Fields are addressed by their dotted path in the event's array shape and
    | are "public" unless listed, so a new metadata key is never over-shared by
    | accident. Because the conventional "old"/"new" keys carry a task title in
    | one event and a free-text cancellation message in the next, an action may
    | override the classification of a path.
    |
    | The internal record — the outbox row and the activity feed — always keeps
    | full fidelity; only the copy handed to a redacting sink is minimised.
    |
    */

    'pii' => [

        'strategies' => [
            DataClass::Public->value => RedactionStrategy::Keep->value,
            DataClass::Pii->value => RedactionStrategy::Tokenize->value,
            DataClass::Sensitive->value => RedactionStrategy::Drop->value,
        ],

        'fields' => [
            'actor_id' => DataClass::Pii->value,
            'context' => [
                'ip' => DataClass::Pii->value,
                'user_agent' => DataClass::Sensitive->value,
                'token_name' => DataClass::Sensitive->value,
            ],
            'metadata' => [
                'email' => DataClass::Pii->value,
                'member' => DataClass::Pii->value,
                'member_id' => DataClass::Pii->value,
                'passkey' => DataClass::Sensitive->value,
                'token' => DataClass::Sensitive->value,
            ],
        ],

        'actions' => [
            // Name snapshots of the users assigned and unassigned.
            'assignee_changed' => [
                'metadata' => [
                    'old' => DataClass::Sensitive->value,
                    'new' => DataClass::Sensitive->value,
                ],
            ],
            // Carries the free-text cancellation message alongside the reason.
            'canceled' => [
                'metadata' => ['new' => DataClass::Sensitive->value],
            ],
            // The free-text reason the comment was deleted.
            'comment_deleted' => [
                'metadata' => ['new' => DataClass::Sensitive->value],
            ],
            // Uploaded file names.
            'attachment_added' => [
                'metadata' => ['old' => DataClass::Sensitive->value],
            ],
            'attachment_removed' => [
                'metadata' => ['old' => DataClass::Sensitive->value],
            ],
            // The downloaded file's name — free text that can itself carry PII.
            'attachment_downloaded' => [
                'metadata' => ['name' => DataClass::Sensitive->value],
            ],
        ],

        /*
        | Pseudonymous tokens are a keyed hash of the value, so the same person
        | tokenises alike in every published event while the token cannot be
        | reversed without the salt. Rotating the salt (or the application key
        | it falls back to) invalidates every token already published — the
        | crypto-shredding lever for pseudonymised data.
        */

        'token_salt' => env('AUDIT_PII_TOKEN_SALT'),

    ],

];
