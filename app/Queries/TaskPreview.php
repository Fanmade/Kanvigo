<?php

namespace App\Queries;

use App\Models\Task;
use App\Models\User;

/**
 * The compact preview of a task shown in the #reference hovercard: enough to know
 * what a reference points at — its title, status, priority, assignees and subtask
 * progress — without opening the task.
 */
class TaskPreview
{
    /**
     * @return array{
     *     reference: string,
     *     title: string,
     *     url: string,
     *     status: string,
     *     priority: string,
     *     assignees: list<string>,
     *     progress: array{done: int, total: int, label: string}|null,
     *     is_blocked: bool,
     * }
     */
    public function handle(Task $task): array
    {
        $progress = $task->progress();

        return [
            'reference' => $task->reference,
            'title' => $task->title,
            'url' => route('task.show', [
                'short_name' => $task->project->short_name,
                'task_number' => $task->task_number,
            ]),
            'status' => $task->status->label(),
            'priority' => $task->priority->label(),
            'assignees' => array_values($task->assignees->map(static fn (User $user): string => (string) $user->name)->all()),
            // The label is localized here (not in JS, which has no translator).
            'progress' => $progress->total > 0
                ? [
                    'done' => $progress->done,
                    'total' => $progress->total,
                    'label' => __(':done/:total done', ['done' => $progress->done, 'total' => $progress->total]),
                ]
                : null,
            'is_blocked' => $task->isBlocked(),
        ];
    }
}
