<?php

namespace App\Concerns;

use App\Actions\ChangeTaskStatus;
use App\Enums\Status;
use App\Models\Task;
use App\Support\StatusCascadeResult;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

trait BuildsKanbanColumns
{
    /**
     * Split a set of tasks into board columns by status. Within each column the
     * tasks keep their manual board order (the `position` set by drag-and-drop),
     * with the id as a stable tie-breaker. Project context travels with each
     * task's reference, so the board is no longer grouped by project.
     *
     * An optional per-column text search (keyed by `Status->value`) narrows a
     * column to the cards whose title or reference contains the term, so each
     * lane can be filtered independently without scanning the whole list.
     *
     * @param  Collection<int, Task>  $tasks
     * @param  array<string, string|null>  $search
     * @return array<int, array{status: Status, tasks: Collection<int, Task>, search: string|null}>
     */
    protected function buildColumns(Collection $tasks, array $search = []): array
    {
        // Canceled tasks are terminal and never belong on an active board lane, so
        // drop them up front. (Done tasks keep their Done column; only the
        // abandoned ones disappear — they remain on the project overview.)
        $tasks = $tasks->reject(static fn (Task $task): bool => $task->status === Status::Canceled)->values();

        // Eager-load each visible card's breadcrumb ancestors in one batched pass
        // (the relation hydrates onto the same instances rendered below), so deep
        // cards never trigger a per-card ancestor lookup. loadMissing keeps a
        // cached collection (which already carries ancestors) from re-querying.
        EloquentCollection::make($tasks->all())->loadMissing('ancestors');

        $columns = [];

        foreach (Status::columns() as $status) {
            $columnTasks = $tasks->where('status', $status)
                ->sortBy([
                    ['position', 'asc'],
                    ['id', 'asc'],
                ])
                ->values();

            $term = $search[$status->value] ?? null;

            $columns[] = [
                'status' => $status,
                'tasks' => $this->filterColumnBySearch($columnTasks, $term),
                'search' => $term,
            ];
        }

        return $columns;
    }

    /**
     * Narrow a single column's tasks to those whose title or reference contains
     * the (case-insensitive) search term. A blank term leaves the column intact.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    protected function filterColumnBySearch(Collection $tasks, ?string $term): Collection
    {
        $term = mb_strtolower(trim((string) $term));

        if ($term === '') {
            return $tasks;
        }

        return $tasks->filter(static fn (Task $task): bool => str_contains(mb_strtolower($task->title), $term)
            || str_contains(mb_strtolower($task->reference), $term))
            ->values();
    }

    /**
     * Authorize and apply a status change to a task, recording the activity.
     * Moved tasks land at the bottom of the destination column. Returns the
     * cascade result (so callers can prompt about a closed-out parent), or null
     * when nothing changed.
     */
    protected function applyTaskMove(Task $task, string $status): ?StatusCascadeResult
    {
        $this->authorize('updateStatus', $task);

        $new = Status::tryFrom($status);

        if ($new === null || $task->status === $new) {
            return null;
        }

        // Position is set first; the status change (and its cascade) is persisted
        // by the shared action, which saves the model — carrying the new position.
        $task->position = (Task::where('status', $new)->max('position') ?? 0) + 1;

        return app(ChangeTaskStatus::class)->handle($task, $new);
    }

    /**
     * Authorize and archive a task, hiding it from the board.
     */
    protected function applyTaskArchive(Task $task): void
    {
        $this->authorize('updateStatus', $task);

        $task->archive();
    }

    /**
     * Authorize and restore a task from the archive.
     */
    protected function applyTaskUnarchive(Task $task): void
    {
        $this->authorize('updateStatus', $task);

        $task->unarchive();
    }

    /**
     * Authorize and apply a drag-and-drop placement: move the task to the given
     * status (if changed, recording the activity) and reposition it between its
     * new neighbours — the cards immediately above ($beforeId) and below
     * ($afterId) it in the destination column.
     */
    protected function applyTaskReorder(Task $task, string $status, ?int $beforeId, ?int $afterId): ?StatusCascadeResult
    {
        $this->authorize('updateStatus', $task);

        $new = Status::tryFrom($status);

        if ($new === null) {
            return null;
        }

        $task->position = $this->positionBetween($beforeId, $afterId);

        if ($task->status === $new) {
            $task->save();

            return null;
        }

        // A genuine status change goes through the shared action so the cascade
        // and activity logging stay consistent with every other entry point.
        return app(ChangeTaskStatus::class)->handle($task, $new);
    }

    /**
     * The position value that places a card between the given neighbours. Uses
     * the midpoint of the two surrounding positions so only the moved card is
     * rewritten — this stays correct on both the project and the cross-project
     * global board, since the neighbours are concrete tasks shared by both.
     */
    protected function positionBetween(?int $beforeId, ?int $afterId): float
    {
        $before = $beforeId !== null ? Task::find($beforeId)?->position : null;
        $after = $afterId !== null ? Task::find($afterId)?->position : null;

        return match (true) {
            $before !== null && $after !== null => ($before + $after) / 2,
            $before !== null => $before + 1,
            $after !== null => $after - 1,
            default => 0.0,
        };
    }
}
