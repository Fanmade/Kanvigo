<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A database notification the user can dismiss.
 *
 * Dismissing soft-deletes the row, so it disappears from the panel and the
 * unread count while staying readable as archive. The prunable query below
 * force-deletes dismissed notifications a month later, so the table stays
 * bounded.
 */
class Notification extends DatabaseNotification
{
    use Prunable;
    use SoftDeletes;

    /**
     * How long a dismissed notification is kept before it is deleted for good.
     */
    public const int RETENTION_DAYS = 30;

    /**
     * Notifications dismissed longer than the retention window ago. Only
     * dismissed rows are pruned — an undismissed notification is kept however
     * old it is.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return $this->newQuery()
            ->onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }
}
