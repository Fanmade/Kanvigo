<?php

namespace App\Actions;

use App\Enums\CascadePreference;
use App\Enums\Status;
use App\Models\Task;
use App\Models\User;
use App\Support\Facades\Audit;
use App\Support\StatusCascadeResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for changing a task's status, shared by the task
 * page, both boards and the MCP tool. It applies the parent/child cascade from
 * the RFC atomically and records every resulting change in the activity log:
 *
 * - Parent → terminal (Done/Canceled) with open subtasks: optionally apply the
 *   same status to the open subtree ({@see resolveCascade()} decides from the
 *   caller's choice or the actor's {@see CascadePreference}).
 * - Child → In progress: pull a not-yet-started parent into progress, silently,
 *   reporting enough state to undo just that bump.
 * - Child → terminal that was the parent's last open child: close the parent too
 *   under the "always" preference, or report it so the UI can prompt under "ask".
 *   Under "never" — and never silently otherwise — the parent is left untouched.
 */
class ChangeTaskStatus
{
    /**
     * The per-user preference key controlling the Done/Cancel children cascade.
     */
    public const string PREFERENCE_KEY = 'status_cascade';

    /**
     * The per-user preference key controlling whether closing the last open
     * subtask also closes the parent ({@see CascadePreference}: ask/always/never).
     */
    public const string PARENT_CLOSE_PREFERENCE_KEY = 'parent_close';

    /**
     * Apply $new to $task and run the cascade in a single transaction.
     *
     * @param  bool|null  $cascadeToChildren  when $new is terminal and the task has open
     *                                        descendants: true also applies $new to them, false leaves them, null resolves
     *                                        from the actor's preference (under "ask", Done leaves and Cancel cascades).
     */
    public function handle(Task $task, Status $new, ?bool $cascadeToChildren = null): StatusCascadeResult
    {
        if ($task->status === $new) {
            return new StatusCascadeResult;
        }

        /** @var array<int, array{id: int, status: string}> $undo */
        $undo = [];
        $cascaded = 0;
        $parentBumped = false;
        $parent = $task->parent;
        $parentPreviousStatus = null;
        $parentCloseable = false;
        $parentClosed = false;
        $parentPref = $this->cascadePreference(self::PARENT_CLOSE_PREFERENCE_KEY);

        DB::transaction(function () use ($task, $new, $cascadeToChildren, $parent, $parentPref, &$undo, &$cascaded, &$parentBumped, &$parentPreviousStatus, &$parentCloseable, &$parentClosed): void {
            $this->changeOne($task, $new, $undo);

            if ($new->isTerminal() && $this->resolveCascade($new, $cascadeToChildren)) {
                foreach ($task->openDescendants() as $descendant) {
                    $this->changeOne($descendant, $new, $undo);
                    $cascaded++;
                }
            }

            if ($new === Status::InProgress
                && $parent !== null
                && ! $parent->status->isTerminal()
                && $parent->status !== Status::InProgress
            ) {
                $parentPreviousStatus = $parent->status->value;
                $this->changeOne($parent, Status::InProgress, $undo);
                $parentBumped = true;
            }

            // Closing a child that was the parent's last open child: under "always"
            // close the parent now; under "ask" only flag it for the UI to prompt;
            // under "never" leave it. The parent is never closed silently.
            $parentCloseable = $new->isTerminal()
                && $parent !== null
                && ! $parent->status->isTerminal()
                && $parent->openChildCount() === 0;

            if ($parentCloseable && $parentPref === CascadePreference::Always) {
                $this->changeOne($parent, $new, $undo);
                $parentClosed = true;
            }
        });

        return new StatusCascadeResult(
            undo: $undo,
            cascadedChildren: $cascaded,
            parentBumped: $parentBumped,
            parentClosed: $parentClosed,
            parentClosedOut: $parentCloseable && ! $parentClosed && $parentPref === CascadePreference::Ask,
            parentId: $parent?->getKey(),
            parentPreviousStatus: $parentPreviousStatus,
        );
    }

    /**
     * Restore the given tasks to the statuses captured in an undo snapshot,
     * atomically and without recording further activity — the undo neutralizes a
     * change the user did not intend.
     *
     * @param  array<int, array{id: int, status: string}>  $undo
     */
    public function revert(array $undo): void
    {
        DB::transaction(function () use ($undo): void {
            foreach ($undo as $entry) {
                $task = Task::find($entry['id']);
                $status = Status::tryFrom($entry['status']);

                if ($task === null || $status === null || $task->status === $status) {
                    continue;
                }

                $task->status = $status;
                $task->save();
            }
        });
    }

    /**
     * Apply a status to one task, capturing its prior status for undo and logging
     * the change. Skips tasks already at the target status.
     *
     * @param  array<int, array{id: int, status: string}>  $undo
     */
    private function changeOne(Task $task, Status $new, array &$undo): void
    {
        if ($task->status === $new) {
            return;
        }

        $old = $task->status;
        $undo[] = ['id' => $task->id, 'status' => $old->value];

        $task->status = $new;
        $task->save();

        Audit::record($task->contentAuditEvent('status_changed', 'status', $old->value, $new->value));
    }

    /**
     * Whether the terminal cascade should reach the open subtree, from the
     * caller's explicit choice or, failing that, the actor's preference.
     */
    private function resolveCascade(Status $new, ?bool $explicit): bool
    {
        if ($explicit !== null) {
            return $explicit;
        }

        return match ($this->cascadePreference(self::PREFERENCE_KEY)) {
            CascadePreference::Always => true,
            CascadePreference::Never => false,
            CascadePreference::Ask => $new === Status::Canceled,
        };
    }

    /**
     * The actor's cascade preference for the given preference key (the status
     * cascade or the parent-close prompt), defaulting to "ask".
     */
    private function cascadePreference(string $key): CascadePreference
    {
        $user = Auth::user();
        $value = $user instanceof User
            ? $user->preference($key, CascadePreference::Ask->value)
            : CascadePreference::Ask->value;

        return CascadePreference::tryFrom((string) $value) ?? CascadePreference::Ask;
    }
}
