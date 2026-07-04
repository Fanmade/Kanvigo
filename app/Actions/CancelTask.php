<?php

namespace App\Actions;

use App\Concerns\Cancellable;
use App\Enums\CancelReason;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a task together with its open subtree, and reopens a canceled task.
 * The single source of truth for cancellation, shared by the task page and the
 * MCP tool — mirrors {@see ChangeTaskStatus} for status changes.
 *
 * Cancelling a parent abandons every open (non-terminal) descendant with the
 * same reason, so no live work is left orphaned under a canceled parent. Already
 * Done or already Canceled descendants are terminal and left untouched. The
 * per-task set-and-log lives on {@see Cancellable}; this action
 * adds the cascade in a single transaction.
 */
class CancelTask
{
    /**
     * Cancel the task and its open subtree with the given reason and optional
     * message (the message is recorded on the task itself, not the cascaded
     * subtasks). Returns how many open descendants were also canceled.
     */
    public function cancel(Task $task, CancelReason $reason, ?string $message = null): int
    {
        if ($task->isCanceled()) {
            return 0;
        }

        return DB::transaction(function () use ($task, $reason, $message): int {
            $task->cancel($reason, $message);

            $cascaded = 0;

            foreach ($task->openDescendants() as $descendant) {
                if ($descendant->cancel($reason)) {
                    $cascaded++;
                }
            }

            return $cascaded;
        });
    }

    /**
     * Reopen a canceled task, returning it to Planned. Only the task itself is
     * reopened; subtasks canceled by the cascade stay canceled (they may have
     * been abandoned on their own merits).
     */
    public function reopen(Task $task): void
    {
        $task->reopen();
    }
}
