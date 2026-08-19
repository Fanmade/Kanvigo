<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * A database notification the user can dismiss.
 *
 * Dismissing soft-deletes the row, so it disappears from the panel and the
 * unread count while staying readable as archive. The prunable query below
 * force-deletes notifications the reader is done with — dismissed, or merely
 * read — once the retention window has passed, so the table stays bounded.
 */
class Notification extends DatabaseNotification
{
    use Prunable;
    use SoftDeletes;

    /**
     * How long a notification is kept after it was dismissed or read.
     */
    public static function retentionDays(): int
    {
        return (int) config('kanvigo.notifications.retention_days');
    }

    /**
     * Notifications dismissed or read longer than the retention window ago.
     *
     * An unread, undismissed notification is never pruned, however old it is:
     * nothing addressed at someone may disappear before they have seen it. A
     * retention window of 0 disables pruning entirely.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = self::retentionDays();

        if ($days <= 0) {
            return $this->newQuery()->whereRaw('1 = 0');
        }

        $cutoff = Carbon::now()->subDays($days);

        return $this->newQuery()
            ->withTrashed()
            ->where(static function (Builder $done) use ($cutoff): void {
                $done->where('deleted_at', '<=', $cutoff)
                    ->orWhere('read_at', '<=', $cutoff);
            });
    }
}
