<?php

namespace App\Support;

use App\Actions\ChangeTaskStatus;
use App\Enums\Status;

/**
 * The outcome of a {@see ChangeTaskStatus} call: what else changed
 * alongside the task the user touched, and enough state to undo the surprising
 * side-effects.
 */
readonly class StatusCascadeResult
{
    /**
     * @param  array<int, array{id: int, status: string}>  $undo  every task changed by
     *                                                            this call paired with the status it held beforehand, newest last
     * @param  int  $cascadedChildren  how many open descendants inherited the new status
     * @param  bool  $parentBumped  whether a not-yet-started parent was pulled into "In progress"
     * @param  bool  $parentClosed  whether this change also closed the parent (the "always" preference)
     * @param  bool  $parentClosedOut  whether the caller should prompt to close the now-childless parent
     *                                 (true only under the "ask" preference; false when auto-closed or "never")
     * @param  int|null  $parentId  the affected parent, when one was bumped or closed out
     * @param  string|null  $parentPreviousStatus  the bumped parent's prior status value, for undo
     */
    public function __construct(
        public array $undo = [],
        public int $cascadedChildren = 0,
        public bool $parentBumped = false,
        public bool $parentClosed = false,
        public bool $parentClosedOut = false,
        public ?int $parentId = null,
        public ?string $parentPreviousStatus = null,
    ) {}

    /**
     * Whether the status actually changed (false for a no-op call).
     */
    public function changed(): bool
    {
        return $this->undo !== [];
    }

    /**
     * The undo entry that reverts only the silent parent bump, or null when no
     * parent was bumped.
     *
     * @return array<int, array{id: int, status: string}>
     */
    public function parentBumpUndo(): array
    {
        if (! $this->parentBumped || $this->parentId === null || $this->parentPreviousStatus === null) {
            return [];
        }

        return [['id' => $this->parentId, 'status' => $this->parentPreviousStatus]];
    }

    /**
     * The status the bumped parent held before it was pulled into progress.
     */
    public function parentPreviousStatus(): ?Status
    {
        return $this->parentPreviousStatus !== null ? Status::from($this->parentPreviousStatus) : null;
    }
}
