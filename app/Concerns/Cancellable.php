<?php

namespace App\Concerns;

use App\Enums\CancelReason;
use App\Enums\Status;
use App\Models\Activity;
use App\Support\Facades\Audit;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Cancelling abandons a task with a reason (and optional message), moving it to
 * the terminal {@see Status::Canceled} state while keeping it on the record
 * instead of deleting it. Reopening clears the cancellation and returns the task
 * to {@see Status::Planned}. Both record an audit entry — and so notify
 * subscribers — through {@see Audit::record()}.
 *
 * Requires {@see LogsActivity} on the model. Operates on the single task only;
 * any parent/child status cascade is handled separately by the status flow.
 *
 * @property Carbon|null $canceled_at
 * @property CancelReason|null $cancel_reason
 * @property string|null $cancel_message
 * @property Status $status
 */
trait Cancellable
{
    /**
     * Cancel the task with a reason and an optional message, recording the
     * activity. No-op (returns false) if it is already canceled.
     */
    public function cancel(CancelReason $reason, ?string $message = null): bool
    {
        if ($this->canceled_at !== null) {
            return false;
        }

        $message = $message !== null && trim($message) !== '' ? trim($message) : null;

        $this->canceled_at = Carbon::now();
        $this->cancel_reason = $reason;
        $this->cancel_message = $message;
        $this->status = Status::Canceled;
        $this->save();

        Audit::record($this->contentAuditEvent(
            'canceled',
            'cancellation',
            null,
            Activity::encodeValue(['reason' => $reason->value, 'message' => $message]),
        ));

        return true;
    }

    /**
     * Reopen a canceled task: clear the cancellation and return it to Planned,
     * recording the activity. No-op (returns false) if it is not canceled.
     */
    public function reopen(): bool
    {
        if ($this->canceled_at === null) {
            return false;
        }

        $previousReason = $this->cancel_reason;

        $this->canceled_at = null;
        $this->cancel_reason = null;
        $this->cancel_message = null;
        $this->status = Status::Planned;
        $this->save();

        Audit::record($this->contentAuditEvent('reopened', 'cancellation', $previousReason?->value, null));

        return true;
    }

    /**
     * Whether the task has been canceled (abandoned with a reason).
     */
    public function isCanceled(): bool
    {
        return $this->canceled_at !== null;
    }

    /**
     * Limit the query to tasks that are not canceled.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notCanceled(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('canceled_at'));
    }

    /**
     * Limit the query to canceled tasks.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function canceled(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('canceled_at'));
    }
}
