<?php

namespace App\Concerns;

use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

/**
 * Lets a task nest under another task to form a tree. Builds on the
 * adjacency-list package for the recursive `ancestors` / `descendants` /
 * `children` / `parent` relations and guards every parent assignment against
 * self-parenting, cycles and the configured maximum nesting depth.
 *
 * @phpstan-require-extends Model
 */
trait Nestable
{
    use HasRecursiveRelationships;

    /**
     * Validate the parent whenever it is set or changed, on create and on
     * re-parent. A null parent (a root task) is always allowed.
     */
    public static function bootNestable(): void
    {
        static::saving(static function (Task $task): void {
            if ($task->isDirty('parent_id') && $task->parent_id !== null) {
                $task->assertValidParent();
            }
        });
    }

    /**
     * The task's level in the tree, counting the root as level 1.
     */
    public function nestingDepth(): int
    {
        return $this->ancestors()->count() + 1;
    }

    /**
     * The height of the subtree rooted at this task, counting the task itself
     * as level 1 (a leaf task has a height of 1).
     */
    public function subtreeHeight(): int
    {
        if (! $this->exists) {
            return 1;
        }

        $deepest = $this->descendants()->get()->max($this->getDepthName());

        return $deepest === null ? 1 : (int) $deepest + 1;
    }

    /**
     * Ensure the pending `parent_id` is a real task that does not point at the
     * task itself, close a cycle, or push the subtree past the depth limit.
     *
     * @throws InvalidArgumentException
     */
    protected function assertValidParent(): void
    {
        if ($this->parent_id === $this->getKey()) {
            throw new InvalidArgumentException('A task cannot be its own parent.');
        }

        $parent = Task::find($this->parent_id);

        if ($parent === null) {
            throw new InvalidArgumentException('The parent task does not exist.');
        }

        // Moving a task under one of its own descendants would close a cycle.
        if ($this->exists && $this->descendants()->whereKey($parent->getKey())->exists()) {
            throw new InvalidArgumentException('A task cannot be nested under its own descendant.');
        }

        $maxDepth = (int) config('kanbrio.tasks.max_depth');

        if ($parent->nestingDepth() + $this->subtreeHeight() > $maxDepth) {
            throw new InvalidArgumentException(
                "A task cannot be nested deeper than {$maxDepth} levels."
            );
        }
    }
}
