<?php

namespace App\Audit\Sinks;

use App\Audit\Pii\AuditRedactor;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use Kanvigo\Audit\Contracts\SinkPolicy;

/**
 * Wraps a sink that ships events out of the system, so it only ever sees the
 * redacted copy. Registered by marking the sink 'redact' => true in
 * config/audit.php; the wrapped sink keeps its own accepts() filter and policy.
 */
readonly class RedactingSink implements AuditSink
{
    public function __construct(protected AuditSink $inner, protected AuditRedactor $redactor) {}

    public function accepts(AuditEvent $event): bool
    {
        return $this->inner->accepts($event);
    }

    public function record(AuditEvent $event): void
    {
        $this->inner->record($this->redactor->redact($event));
    }

    public function policy(): SinkPolicy
    {
        return $this->inner->policy();
    }

    public function inner(): AuditSink
    {
        return $this->inner;
    }
}
