<?php

namespace App\Console\Commands;

use App\Audit\AuditManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use stdClass;
use Throwable;

#[Signature('audit:outbox:drain')]
#[Description('Ship pending audit-outbox events to the queued audit sinks, in outbox order.')]
class DrainAuditOutbox extends Command
{
    /**
     * Deliver pending rows to every accepting queued sink in outbox-id order
     * (the single-writer total order queued sinks rely on). A row is marked
     * dispatched only when every accepting sink took it; a failed row stays
     * pending and is retried on the next run — delivery is at-least-once, so
     * sinks dedupe on the event's idempotency key.
     */
    public function handle(AuditManager $audit): int
    {
        $sinks = $audit->queuedSinks();

        if ($sinks === []) {
            $this->info('No queued audit sinks registered; nothing to drain.');

            return self::SUCCESS;
        }

        $shipped = 0;
        $failed = 0;
        $cursor = 0;

        do {
            $rows = DB::table('audit_outbox')
                ->whereNull('dispatched_at')
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(100)
                ->get();

            foreach ($rows as $row) {
                $cursor = $row->id;

                $this->ship($row, $sinks) ? $shipped++ : $failed++;
            }
        } while ($rows->isNotEmpty());

        $this->info("Shipped {$shipped} audit event(s); {$failed} left pending for retry.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Deliver one outbox row to every accepting sink; true when all took it.
     *
     * @param  list<AuditSink>  $sinks
     */
    protected function ship(stdClass $row, array $sinks): bool
    {
        $event = AuditEvent::fromArray(
            json_decode((string) $row->event, true, flags: JSON_THROW_ON_ERROR),
        );

        $delivered = true;

        foreach ($sinks as $sink) {
            if (! $sink->accepts($event)) {
                continue;
            }

            try {
                $sink->record($event);
            } catch (Throwable $exception) {
                report($exception);
                $delivered = false;
            }
        }

        if ($delivered) {
            DB::table('audit_outbox')->where('id', $row->id)->update(['dispatched_at' => now()]);
        }

        return $delivered;
    }
}
