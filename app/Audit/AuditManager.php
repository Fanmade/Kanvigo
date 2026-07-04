<?php

namespace App\Audit;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use Kanvigo\Audit\Contracts\Exceptions\AuditIntegrityException;
use Throwable;

/**
 * The single audit emit point, built on a transactional outbox:
 *
 * 1. Fail-closed sinks run synchronously, pre-commit, inside the domain
 *    transaction — a throw rolls the action back ("no guaranteed record →
 *    no action"), and emitting outside a transaction while such a sink is
 *    registered throws an AuditIntegrityException.
 * 2. The event is INSERTed into audit_outbox in whatever transaction is
 *    active, so it rolls back with an aborted action.
 * 3. After commit, sync fail-open sinks run inline with failures isolated;
 *    queued sinks are shipped later by the audit:outbox:drain worker in
 *    outbox-id order (at-least-once, deduped via the idempotency key).
 */
class AuditManager
{
    /**
     * @var list<AuditSink>|null
     */
    protected ?array $sinks = null;

    public function __construct(protected ContextResolver $context) {}

    public function record(AuditEvent $event): void
    {
        $event = $this->context->stamp($event);

        if ($event->idempotencyKey === null) {
            $event = $event->withIdempotencyKey((string) Str::uuid7());
        }

        $sinks = $this->sinks();

        $this->recordFailClosed($event, $sinks);

        $this->enqueue($event, $sinks);

        DB::afterCommit(function () use ($event, $sinks): void {
            $this->dispatchSync($event, $sinks);
        });
    }

    /**
     * The registered sinks, resolved once from config('audit.sinks').
     *
     * @return list<AuditSink>
     */
    public function sinks(): array
    {
        return $this->sinks ??= array_map(static function (string $class): AuditSink {
            $sink = app($class);

            if (! $sink instanceof AuditSink) {
                throw new InvalidArgumentException("Audit sink [{$class}] must implement ".AuditSink::class.'.');
            }

            return $sink;
        }, array_values(config('audit.sinks', [])));
    }

    /**
     * The registered sinks the outbox drain worker ships events to.
     *
     * @return list<AuditSink>
     */
    public function queuedSinks(): array
    {
        return array_values(array_filter(
            $this->sinks(),
            static fn (AuditSink $sink): bool => $sink->policy()->isQueued(),
        ));
    }

    /**
     * Forget the resolved sink instances (e.g. after swapping the config in
     * a test), so the next record() re-reads config('audit.sinks').
     */
    public function flushSinks(): void
    {
        $this->sinks = null;
    }

    /**
     * Run the accepting fail-closed sinks synchronously, pre-commit, inside
     * the domain transaction. Their exceptions propagate on purpose: the
     * surrounding transaction rolls back and the action is aborted.
     *
     * @param  list<AuditSink>  $sinks
     */
    protected function recordFailClosed(AuditEvent $event, array $sinks): void
    {
        foreach ($sinks as $sink) {
            if (! $sink->policy()->isFailClosed() || ! $sink->accepts($event)) {
                continue;
            }

            if (! $this->inTransaction()) {
                throw new AuditIntegrityException(
                    "A fail-closed audit sink accepted \"{$event->action}\" outside a database transaction; "
                    .'wrap the audited mutation in DB::transaction() so a failed audit write can abort it.',
                );
            }

            $sink->record($event);
        }
    }

    /**
     * INSERT the event into the outbox within the active transaction. When no
     * queued sink wants it there is nothing left to drain, so the row is born
     * dispatched and only serves as the replayable record until pruned.
     *
     * @param  list<AuditSink>  $sinks
     */
    protected function enqueue(AuditEvent $event, array $sinks): void
    {
        $awaitsDrain = array_any(
            $sinks,
            static fn (AuditSink $sink): bool => $sink->policy()->isQueued() && $sink->accepts($event),
        );

        DB::table('audit_outbox')->insert([
            'idempotency_key' => $event->idempotencyKey,
            'event' => json_encode($event->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'dispatched_at' => $awaitsDrain ? null : now(),
        ]);
    }

    /**
     * Deliver the event to the accepting sync fail-open sinks, isolating
     * failures so one sink can neither break the request nor the others.
     *
     * @param  list<AuditSink>  $sinks
     */
    protected function dispatchSync(AuditEvent $event, array $sinks): void
    {
        foreach ($sinks as $sink) {
            $policy = $sink->policy();

            if ($policy->isQueued() || $policy->isFailClosed() || ! $sink->accepts($event)) {
                continue;
            }

            try {
                $sink->record($event);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Whether an application-level transaction is active. Asks the
     * transactions manager rather than DB::transactionLevel() so the
     * test framework's wrapping transaction doesn't count.
     */
    protected function inTransaction(): bool
    {
        /** @var DatabaseTransactionsManager $manager */
        $manager = app('db.transactions');

        return $manager->callbackApplicableTransactions()->isNotEmpty();
    }
}
