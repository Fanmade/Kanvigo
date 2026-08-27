<?php

namespace App\Queries;

use App\Enums\WaitingScope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tasks currently held up on a person — either the ones waiting on this user
 * to answer, or the ones this user is waiting on somebody else for. One query
 * serves both sides of the "Waiting on" page; the scope only swaps which column
 * is matched.
 *
 * Canceled and archived tasks drop out: nobody owes an answer on abandoned work.
 * Results come oldest-wait-first, since the longest wait is the one that needs
 * answering, and are restricted to projects the user can still see, so losing
 * membership also drops the task off their list.
 *
 * A builder is returned rather than a collection because the two callers consume
 * it differently: the page lists the tasks, the sidebar badge only counts them.
 */
class WaitingTasks
{
    /**
     * @return Builder<Task>
     */
    public function handle(User $user, WaitingScope $scope): Builder
    {
        $column = $scope === WaitingScope::OnMe ? 'waiting_on_user_id' : 'waiting_by_user_id';

        return Task::query()
            ->where($column, $user->getKey())
            ->whereNotNull('waiting_on_user_id')
            ->whereNull('canceled_at')
            ->whereNull('archived_at')
            ->whereIn('project_id', $user->projectIdsWithPermission('view-project'))
            ->with(['project', 'waitingOn', 'waitingBy'])
            ->orderBy('waiting_since')
            ->orderBy('id');
    }
}
