<?php

namespace App\Support;

use App\Enums\Status;
use App\Models\Dependency;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Determines which of a set of tasks are blocked — i.e. have at least one
 * blocker that is not yet complete — in a fixed number of queries, so the board
 * can flag blocked cards without an N+1 over every task and its blockers.
 */
class BlockedTasks
{
    /**
     * The ids of the given tasks that are currently blocked.
     *
     * @param  array<int, int>  $taskIds
     * @return array<int, int>
     */
    public static function ids(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $taskMorph = (new Task)->getMorphClass();

        $links = Dependency::query()
            ->where('dependent_type', $taskMorph)
            ->whereIn('dependent_id', $taskIds)
            ->get(['dependent_id', 'blocker_type', 'blocker_id']);

        if ($links->isEmpty()) {
            return [];
        }

        $completeBlockers = self::completeBlockerKeys($links, $taskMorph);

        $blocked = [];

        foreach ($links as $link) {
            $key = $link->blocker_type.':'.$link->blocker_id;

            if (! isset($completeBlockers[$key])) {
                $blocked[$link->dependent_id] = true;
            }
        }

        return array_keys($blocked);
    }

    /**
     * The "type:id" keys of the task blockers in the given links that are
     * complete, resolved in a single query.
     *
     * @param  Collection<int, Dependency>  $links
     * @return array<string, true>
     */
    private static function completeBlockerKeys(Collection $links, string $taskMorph): array
    {
        $complete = [];

        $taskBlockerIds = $links->where('blocker_type', $taskMorph)->pluck('blocker_id')->unique()->all();

        if ($taskBlockerIds !== []) {
            Task::query()
                ->whereIn('id', $taskBlockerIds)
                ->where('status', Status::Done)
                ->pluck('id')
                ->each(static function (int $id) use (&$complete, $taskMorph): void {
                    $complete[$taskMorph.':'.$id] = true;
                });
        }

        return $complete;
    }
}
