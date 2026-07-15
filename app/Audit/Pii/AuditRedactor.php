<?php

namespace App\Audit\Pii;

use Kanvigo\Audit\Contracts\AuditContext;
use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * The redaction transform applied at an external boundary: every field the
 * {@see AuditClassifier} marks as personal is pseudonymised, every field it
 * marks as sensitive is dropped, and the rest passes through. The internal
 * record — the outbox row and the activity feed — keeps full fidelity; only
 * the copy handed to an outbound sink is minimised.
 *
 * Tokens are a keyed hash of the value alone, so the same person yields the
 * same token in every event and across fields (an actor_id and the member_id
 * of the same user tokenise alike), while the token cannot be reversed without
 * the salt.
 */
class AuditRedactor
{
    public function __construct(protected AuditClassifier $classifier) {}

    public function redact(AuditEvent $event): AuditEvent
    {
        return new AuditEvent(
            action: $event->action,
            category: $event->category,
            subjectType: $event->subjectType,
            subjectId: $event->subjectId,
            actorId: $this->apply($event, ['actor_id'], $event->actorId),
            metadata: $this->redactMetadata($event),
            tags: $event->tags,
            context: $this->redactContext($event),
            occurredAt: $event->occurredAt,
            idempotencyKey: $event->idempotencyKey,
        );
    }

    /**
     * The stable pseudonym for a value. Salted with AUDIT_PII_TOKEN_SALT when
     * set, falling back to the application key — rotating either invalidates
     * every previously published token, which is the crypto-shredding lever
     * for pseudonymised data.
     */
    public function tokenize(int|string $value): string
    {
        $salt = config('audit.pii.token_salt') ?: config('app.key');

        return 'tok_'.substr(hash_hmac('sha256', (string) $value, (string) $salt), 0, 32);
    }

    /**
     * @return array<string, mixed>
     */
    protected function redactMetadata(AuditEvent $event): array
    {
        $metadata = [];

        foreach ($event->metadata as $key => $value) {
            $redacted = $this->apply($event, ['metadata', (string) $key], $value);

            if ($redacted !== null || $value === null) {
                $metadata[$key] = $redacted;
            }
        }

        return $metadata;
    }

    protected function redactContext(AuditEvent $event): ?AuditContext
    {
        $context = $event->context;

        if ($context === null) {
            return null;
        }

        return new AuditContext(
            source: $context->source,
            ip: $this->apply($event, ['context', 'ip'], $context->ip),
            userAgent: $this->apply($event, ['context', 'user_agent'], $context->userAgent),
            tokenName: $this->apply($event, ['context', 'token_name'], $context->tokenName),
        );
    }

    /**
     * Apply the field's strategy. A dropped value becomes null; the caller
     * decides whether null means "absent" (metadata) or "unset" (context).
     *
     * @param  list<string>  $path
     */
    protected function apply(AuditEvent $event, array $path, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->classifier->strategyForField($event, ...$path)) {
            RedactionStrategy::Keep => $value,
            RedactionStrategy::Drop => null,
            RedactionStrategy::Tokenize => is_scalar($value) ? $this->tokenize((string) $value) : null,
        };
    }
}
