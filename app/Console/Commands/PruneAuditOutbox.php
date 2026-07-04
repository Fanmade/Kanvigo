<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('audit:outbox:prune')]
#[Description('Delete dispatched audit-outbox rows older than the configured retention window.')]
class PruneAuditOutbox extends Command
{
    /**
     * Only fully dispatched rows are pruned — pending rows always survive, no
     * matter their age, so an unavailable queued sink never loses events.
     */
    public function handle(): int
    {
        $days = (int) config('audit.outbox.retention_days');

        if ($days <= 0) {
            $this->info('Outbox retention is disabled; nothing pruned.');

            return self::SUCCESS;
        }

        $pruned = DB::table('audit_outbox')
            ->whereNotNull('dispatched_at')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$pruned} dispatched audit-outbox row(s).");

        return self::SUCCESS;
    }
}
