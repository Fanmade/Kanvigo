<?php

namespace App\Audit;

use App\Models\Attachment;
use App\Models\User;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * Builders for the read/access audit events — "who looked at what" (KAN-400).
 *
 * The first cut is the sensitive-reads slice: reads of the audit export stream,
 * one member viewing another's contact info, and attachment downloads. These are
 * rare, high-value events, so they are emitted inline like any other; the actor
 * and request context are stamped by the {@see AuditManager}.
 *
 * The viewed person is never the event subject — the redactor passes subjects
 * through untouched (they are the "what" an event is about), so a person is
 * carried in the Pii-classified metadata instead and tokenised at the boundary.
 */
final class AccessAudit
{
    /**
     * A read of the instance-wide audit export stream. The actor is the operator
     * whose token holds the `audit` ability; the metadata records how far the
     * poll reached. This event is itself recorded to the outbox but excluded
     * from the stream's own output, so a polling consumer never reads its own
     * reads back (which would keep the stream from ever going quiet).
     */
    public static function streamRead(int $after, int $limit, int $returned): AuditEvent
    {
        return AuditEvent::make('audit_stream_read', AuditCategory::Access)
            ->withMetadata(['after' => $after, 'limit' => $limit, 'returned' => $returned]);
    }

    /**
     * An administrator opening the user-administration directory, which lists
     * every account's contact info at once. Recorded once per panel open (not
     * per row or per render), so it captures "who accessed the directory"
     * without the noise of a per-person event for a bulk view. The operator is
     * the actor; there is no single subject.
     */
    public static function userDirectoryViewed(): AuditEvent
    {
        return AuditEvent::make('user_directory_viewed', AuditCategory::Access);
    }

    /**
     * One member viewing another member's contact info (email). The viewed user
     * travels in the conventional member/member_id metadata keys — both already
     * classified Pii, so they are tokenised in the published stream while the
     * internal outbox keeps the real values.
     */
    public static function contactInfoViewed(User $subject): AuditEvent
    {
        return AuditEvent::make('contact_info_viewed', AuditCategory::Access)
            ->withMetadata(['member_id' => $subject->getKey(), 'member' => $subject->name]);
    }

    /**
     * A download of a stored attachment (via REST, the web app, or a note). The
     * attachment is the subject; its file name — free text that can itself carry
     * PII — is Sensitive metadata, dropped at the boundary but kept internally.
     */
    public static function attachmentDownloaded(Attachment $attachment): AuditEvent
    {
        return AuditEvent::make('attachment_downloaded', AuditCategory::Access)
            ->withSubject($attachment->getMorphClass(), $attachment->getKey())
            ->withMetadata(['name' => $attachment->name]);
    }
}
