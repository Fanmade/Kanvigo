<?php

namespace App\Concerns;

use App\Enums\Status;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Support\Collection;

trait BuildsKanbanColumns
{
    /**
     * Group an ordered set of tasks into board columns. Within each status the
     * tasks are ordered by priority (highest first); equal priorities keep the
     * incoming order, so consecutive tasks of the same story collapse into a
     * single story group.
     *
     * @param  Collection<int, Task>  $tasks  tasks ordered so same-story tasks are adjacent
     * @return array<int, array{status: Status, groups: array<int, array{story: Story, tasks: Collection<int, Task>}>}>
     */
    protected function buildColumns(Collection $tasks): array
    {
        $columns = [];

        foreach (Status::columns() as $status) {
            $groups = [];

            $statusTasks = $tasks->where('status', $status)
                ->sortByDesc(static fn (Task $task): int => $task->priority->value);

            foreach ($statusTasks as $task) {
                $index = count($groups) - 1;

                if ($index >= 0 && $groups[$index]['story']->id === $task->story_id) {
                    $groups[$index]['tasks']->push($task);

                    continue;
                }

                $groups[] = ['story' => $task->story, 'tasks' => collect([$task])];
            }

            $columns[] = ['status' => $status, 'groups' => $groups];
        }

        return $columns;
    }

    /**
     * Authorize and apply a status change to a task, recording the activity.
     */
    protected function applyTaskMove(Task $task, string $status): void
    {
        $this->authorize('updateStatus', $task);

        $new = Status::tryFrom($status);

        if ($new === null || $task->status === $new) {
            return;
        }

        $old = $task->status;
        $task->status = $new;
        $task->save();

        $task->recordActivity('status_changed', 'status', $old->value, $new->value);
    }
}
