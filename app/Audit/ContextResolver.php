<?php

namespace App\Audit;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Kanvigo\Audit\Contracts\AuditContext;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Stamps actor, occurred-at and request/runtime context onto every event
 * before it reaches the outbox and the sinks. The source distinguishes
 * UI, MCP-token, REST-token, queued-job and scheduled/system origins; a
 * null actor is the first-class system actor.
 */
class ContextResolver
{
    /**
     * Whether a queue job is currently being processed (flipped by the queue
     * events the AuditServiceProvider listens to). Jobs carry no request, so
     * this is the only reliable "we are inside a worker" signal.
     */
    protected bool $processingQueueJob = false;

    public function markQueueJob(bool $processing): void
    {
        $this->processingQueueJob = $processing;
    }

    /**
     * Fill in whatever the event doesn't carry yet; explicitly provided
     * values win, so emitters can override any part of the attribution.
     */
    public function stamp(AuditEvent $event): AuditEvent
    {
        if ($event->occurredAt === null) {
            $event = $event->withOccurredAt(now()->toImmutable());
        }

        $actorId = Auth::id();

        if ($event->actorId === null && $actorId !== null) {
            $event = $event->withActor($actorId);
        }

        if ($event->context === null) {
            $event = $event->withContext($this->resolve());
        }

        return $event;
    }

    public function resolve(): AuditContext
    {
        if ($this->processingQueueJob) {
            return new AuditContext(AuditSource::Queue);
        }

        $marker = Context::getHidden('audit.source');

        if (is_string($marker)) {
            return $this->requestContext(AuditSource::from($marker));
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return new AuditContext(AuditSource::System);
        }

        return $this->requestContext(AuditSource::Ui);
    }

    protected function requestContext(AuditSource $source): AuditContext
    {
        $request = request();

        return new AuditContext(
            source: $source,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            tokenName: $this->tokenName(),
        );
    }

    /**
     * The name of the API/MCP token the current action is being performed
     * with, or null for a direct web-session action. A transient (session)
     * token has no name, so only real personal access tokens are attributed.
     */
    protected function tokenName(): ?string
    {
        $user = Auth::user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token->name : null;
    }
}
