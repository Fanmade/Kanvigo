<?php

namespace App\Concerns;

use App\Support\Facades\Audit;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Archiving hides a finished task from the board and project overview
 * without deleting it. It is orthogonal to status: an archived task keeps its
 * status and position and can be restored at any time. Archived items are
 * excluded from the default board/list queries via {@see notArchived()} and
 * surfaced again behind a "Show archived" toggle.
 *
 * Requires {@see LogsActivity} on the model — archiving records an audit entry.
 *
 * @property Carbon|null $archived_at
 */
trait Archivable
{
    /**
     * Archive this item, recording the activity. No-op if already archived.
     */
    public function archive(): void
    {
        if ($this->archived_at !== null) {
            return;
        }

        $this->archived_at = Carbon::now();
        $this->save();

        Audit::record($this->contentAuditEvent('archived'));
    }

    /**
     * Restore this item from the archive, recording the activity. No-op if it is
     * not archived.
     */
    public function unarchive(): void
    {
        if ($this->archived_at === null) {
            return;
        }

        $this->archived_at = null;
        $this->save();

        Audit::record($this->contentAuditEvent('unarchived'));
    }

    /**
     * Whether this item is archived.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Limit the query to items that are not archived.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('archived_at'));
    }

    /**
     * Limit the query to archived items.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function archived(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('archived_at'));
    }
}
