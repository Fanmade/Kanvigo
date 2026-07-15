<?php

namespace App\Audit\Pii;

/**
 * How sensitive a single audit-event field is. The one vocabulary every sink
 * shares: the transport sink turns it into a redaction strategy, the Chronicle
 * bridge turns it into per-subject field encryption.
 */
enum DataClass: string
{
    /**
     * Carries no personal data — references, IDs of things, enum values,
     * counts. Safe to hand to an external consumer verbatim.
     */
    case Public = 'public';

    /**
     * Identifies a natural person (user IDs, e-mail addresses, IP addresses).
     * Consumers still need to correlate these across events, so they are
     * pseudonymised rather than dropped.
     */
    case Pii = 'pii';

    /**
     * Free text or a content snapshot with no correlation value — cancellation
     * messages, comment-delete reasons, user agents, token and file names.
     * Nothing outside the system needs it.
     */
    case Sensitive = 'sensitive';
}
