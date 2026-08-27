<?php

namespace App\Actions;

use App\Models\Task;
use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The single source of truth for putting a task into — or out of — the "waiting
 * on somebody" state, shared by the task page, MCP, the REST API and the
 * comment auto-clear hook.
 *
 * Setting stamps `waiting_since` (so the badge can show how long the wait has
 * run) and subscribes the awaited member to the task, exactly the way assigning
 * someone does, so the feed entry reaches them as a notification. Clearing wipes
 * both columns. Either way the change is recorded as a `waiting_on_changed`
 * content event carrying the names on both sides.
 */
class SetWaitingOn
{
    /**
     * Put the task in the waiting state for the given user, or clear it when
     * `$user` is null. Returns whether anything actually changed — re-setting
     * the same user is a no-op and keeps the original `waiting_since`.
     */
    public function handle(Task $task, ?User $user): bool
    {
        $previous = $task->waitingOn;

        if ($previous?->getKey() === $user?->getKey()) {
            return false;
        }

        $actorId = Auth::id();

        $task->waiting_on_user_id = $user?->getKey();
        $task->waiting_since = $user === null ? null : Carbon::now();
        $task->waiting_by_user_id = $user === null || $actorId === null ? null : (int) $actorId;
        $task->save();
        $task->setRelation('waitingOn', $user);
        $task->unsetRelation('waitingBy');

        // Awaiting someone's input implies interest on their side; the feed
        // notification then reaches them through the ordinary subscriber fan-out.
        if ($user !== null) {
            $task->autoSubscribe([$user->getKey()]);
        }

        Audit::record($task->contentAuditEvent(
            'waiting_on_changed',
            'waiting_on',
            $previous?->name,
            $user?->name,
        ));

        return true;
    }
}
