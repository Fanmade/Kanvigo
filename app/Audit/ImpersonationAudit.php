<?php

namespace App\Audit;

use App\Models\User;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * Builders for the two ends of an impersonation window.
 *
 * The administrator is the subject — impersonation is an act of theirs, and the
 * pair of events brackets everything they did as someone else. The impersonated
 * person travels in the conventional member/member_id metadata keys (both
 * classified Pii, so they are tokenised in the published stream) rather than as
 * the subject, which the redactor passes through untouched.
 *
 * Actions taken *during* the window are attributed to the impersonated account,
 * as they must be — that is who performed them as far as the application is
 * concerned — but each one additionally carries the impersonator's id and an
 * "impersonated" tag, stamped by {@see ContextResolver}.
 */
final class ImpersonationAudit
{
    public static function started(User $administrator, User $target): AuditEvent
    {
        return self::event('impersonation_started', $administrator, $target);
    }

    public static function stopped(User $administrator, User $target): AuditEvent
    {
        return self::event('impersonation_stopped', $administrator, $target);
    }

    private static function event(string $action, User $administrator, User $target): AuditEvent
    {
        return AuditEvent::make($action, AuditCategory::Security)
            ->withActor($administrator->getKey())
            ->withSubject($administrator->getMorphClass(), $administrator->getKey())
            ->withMetadata(['member_id' => $target->getKey(), 'member' => $target->name]);
    }
}
