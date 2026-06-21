<?php

namespace App\Support;

/**
 * A task subtree's completeness, derived from the state of its descendant tasks.
 *
 * "Done" is the only status that counts toward completion; every other status
 * contributes to the total but not the done count. Constructed from plain counts
 * so callers that already aggregate task counts in the database can build it
 * without loading tasks.
 */
readonly class TaskProgress
{
    public function __construct(
        public int $done,
        public int $total,
    ) {}

    /**
     * The share of completed tasks, rounded to a whole percent (0 when empty).
     */
    public function percent(): int
    {
        return $this->total > 0 ? (int) round($this->done / $this->total * 100) : 0;
    }

    /**
     * Whether there are any tasks to report progress on.
     */
    public function hasTasks(): bool
    {
        return $this->total > 0;
    }

    /**
     * Whether every task is done. An empty subtree is never complete: there is
     * nothing finished to unblock the work that depends on it.
     */
    public function isComplete(): bool
    {
        return $this->total > 0 && $this->done === $this->total;
    }
}
