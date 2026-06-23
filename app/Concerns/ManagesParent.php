<?php

namespace App\Concerns;

use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Adds "move task" (re-parent / detach) to a Task view component: choosing a new
 * parent within the same project, or removing the parent to make the task
 * top-level. The nesting rules (self-parenting, cycles, depth) are enforced by
 * the {@see Nestable} trait on save; this trait gates the candidate list to valid
 * targets, records the move, and refreshes the view.
 */
trait ManagesParent
{
    /**
     * A move awaiting confirmation in the picker.
     */
    public bool $movingParent = false;

    /**
     * The chosen new parent id, or null for a top-level task.
     */
    public ?int $newParentId = null;

    /**
     * The task whose parent is being managed.
     */
    abstract protected function reparentable(): Task;

    /**
     * Drop the cached view of {@see reparentable()} after a move so the parent,
     * breadcrumb and depth gates re-read.
     */
    abstract protected function forgetReparentable(): void;

    /**
     * Open the move picker, preselecting the current parent.
     */
    public function startMoveParent(): void
    {
        $this->authorize('update', $this->reparentable());

        $this->newParentId = $this->reparentable()->parent_id;
        $this->movingParent = true;
    }

    /**
     * Dismiss the picker without changing anything.
     */
    public function cancelMoveParent(): void
    {
        $this->reset('movingParent', 'newParentId');
    }

    /**
     * Tasks in the same project the task may be moved under, as
     * `[id => "REF — title"]`. Excludes the task itself, its descendants (which
     * would close a cycle), archived/terminal tasks, and any target without room
     * for the task's whole subtree within the depth limit.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parentMoveOptions(): array
    {
        $task = $this->reparentable();
        $project = $task->project;
        $maxDepth = (int) config('kanvigo.tasks.max_depth');
        $height = $task->subtreeHeight();

        $excluded = array_merge([$task->id], $task->descendants->pluck('id')->all());

        $tasks = $project->tasks()
            ->select(['id', 'parent_id', 'task_number', 'title', 'status', 'archived_at'])
            ->get();

        $depthOf = $this->parentDepthResolver($tasks->keyBy('id'));

        return $tasks
            ->reject(static fn (Task $candidate): bool => in_array($candidate->id, $excluded, true))
            ->reject(static fn (Task $candidate): bool => $candidate->isArchived())
            ->reject(static fn (Task $candidate): bool => $candidate->status->isTerminal())
            ->filter(static fn (Task $candidate): bool => $depthOf($candidate->id) + $height <= $maxDepth)
            ->sortBy('task_number')
            ->mapWithKeys(static fn (Task $candidate): array => [
                $candidate->id => $project->short_name.'-'.$candidate->task_number.' — '.$candidate->title,
            ])
            ->all();
    }

    /**
     * Apply the chosen parent (or detach to top-level), enforcing the nesting
     * rules and recording the move.
     */
    public function moveParent(): void
    {
        $task = $this->reparentable();
        $this->authorize('update', $task);

        $newParentId = $this->newParentId;

        // A no-op (including re-confirming an unchanged terminal/archived parent
        // that the gated list omits) closes the picker without touching anything.
        if ($task->parent_id === $newParentId) {
            $this->reset('movingParent', 'newParentId');

            return;
        }

        if ($newParentId !== null && ! array_key_exists($newParentId, $this->parentMoveOptions())) {
            $this->addError('newParentId', __('The selected parent task is not valid.'));

            return;
        }

        $oldReference = $task->parent?->reference;

        try {
            $task->parent_id = $newParentId;
            $task->save();
        } catch (InvalidArgumentException) {
            // The Nestable guard rejected the move (cycle/depth) despite the gated
            // options — surface it rather than 500.
            $this->addError('newParentId', __('The selected parent task is not valid.'));

            return;
        }

        $newReference = $newParentId !== null ? Task::find($newParentId)?->reference : null;
        $task->recordActivity('parent_changed', 'parent', $oldReference, $newReference);

        $this->reset('movingParent', 'newParentId');
        $this->forgetReparentable();

        Flux::toast(variant: 'success', text: __('Task moved.'));
    }

    /**
     * An in-memory depth resolver (root = level 1) over a keyed task collection,
     * walking the parent chain without extra queries.
     *
     * @param  Collection<int, Task>  $byId
     * @return callable(int): int
     */
    protected function parentDepthResolver(Collection $byId): callable
    {
        return static function (int $id) use ($byId): int {
            $depth = 1;
            $task = $byId->get($id);

            while ($task?->parent_id !== null) {
                $task = $byId->get($task->parent_id);
                $depth++;
            }

            return $depth;
        };
    }
}
