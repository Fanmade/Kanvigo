<?php

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('activity:prune')]
#[Description('Delete activity-feed entries older than the configured retention window.')]
class PruneActivities extends Command
{
    /**
     * How many rows one delete statement covers.
     */
    private const int BATCH_SIZE = 1000;

    /**
     * Pruning by age alone: the feed is read newest-first and the "what did I
     * miss" marker is a timestamp on the reader (users.activities_seen_at), so an
     * old row holds no state anything else depends on. The comment links pointing
     * at an entry (activity_comment) cascade away with it.
     */
    public function handle(): int
    {
        $days = (int) config('kanvigo.activity.retention_days');

        if ($days <= 0) {
            $this->info('Activity retention is disabled; nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $pruned = 0;

        // Deleted in batches of ids: a first run over years of feed would
        // otherwise be one enormous statement, and "DELETE ... LIMIT" is not
        // portable (PostgreSQL has no such clause).
        do {
            /** @var Collection<int, int> $ids */
            $ids = Activity::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::BATCH_SIZE)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $pruned += Activity::query()->whereKey($ids)->delete();
            }
        } while ($ids->isNotEmpty());

        $this->info("Pruned {$pruned} activity feed row(s).");

        return self::SUCCESS;
    }
}
