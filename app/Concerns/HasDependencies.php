<?php

namespace App\Concerns;

use App\Models\Dependency;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Adds directed dependency links to a Task. An item can be "blocked by"
 * other items (its blockers) and can itself block others. Both ends are
 * polymorphic.
 *
 * @phpstan-require-extends Model
 */
trait HasDependencies
{
    /**
     * Remove an item's dependency links — in both directions — when it is deleted,
     * so no link is left pointing at a missing item.
     */
    public static function bootHasDependencies(): void
    {
        static::deleting(static function (Model $model): void {
            $morph = $model->getMorphClass();
            $key = $model->getKey();

            Dependency::query()
                ->where(static fn (Builder $query): Builder => $query->where('dependent_type', $morph)->where('dependent_id', $key))
                ->orWhere(static fn (Builder $query): Builder => $query->where('blocker_type', $morph)->where('blocker_id', $key))
                ->delete();
        });
    }

    /**
     * The links where this item is the blocked one (pointing at its blockers).
     *
     * @return MorphMany<Dependency, $this>
     */
    public function dependencyLinks(): MorphMany
    {
        return $this->morphMany(Dependency::class, 'dependent');
    }

    /**
     * The links where this item is the blocker (pointing at the items it blocks).
     *
     * @return MorphMany<Dependency, $this>
     */
    public function dependentLinks(): MorphMany
    {
        return $this->morphMany(Dependency::class, 'blocker');
    }

    /**
     * The items that block this one.
     *
     * @return Collection<int, Task>
     */
    public function blockers(): Collection
    {
        $blockers = [];

        foreach ($this->dependencyLinks as $link) {
            $blocker = $link->blocker;

            if ($blocker instanceof Task) {
                $blockers[] = $blocker;
            }
        }

        return collect($blockers);
    }

    /**
     * The items this one blocks.
     *
     * @return Collection<int, Task>
     */
    public function blocking(): Collection
    {
        $blocking = [];

        foreach ($this->dependentLinks as $link) {
            $dependent = $link->dependent;

            if ($dependent instanceof Task) {
                $blocking[] = $dependent;
            }
        }

        return collect($blocking);
    }

    /**
     * Whether any of this item's blockers is not yet complete.
     */
    public function isBlocked(): bool
    {
        return $this->blockers()->contains(static fn (Task $blocker): bool => ! $blocker->isComplete());
    }

    /**
     * Record that this item is blocked by the given one, creating the link if it
     * does not already exist.
     *
     * @throws InvalidArgumentException when the link would be a self-dependency or close a cycle.
     */
    public function addBlocker(Task $blocker): Dependency
    {
        if ($this->wouldCreateCycleWith($blocker)) {
            throw new InvalidArgumentException('A dependency cannot create a cycle.');
        }

        $dependency = Dependency::firstOrCreate([
            'dependent_type' => $this->getMorphClass(),
            'dependent_id' => $this->getKey(),
            'blocker_type' => $blocker->getMorphClass(),
            'blocker_id' => $blocker->getKey(),
        ]);

        $this->unsetRelation('dependencyLinks');

        return $dependency;
    }

    /**
     * Remove the link recording that this item is blocked by the given one.
     */
    public function removeBlocker(Task $blocker): void
    {
        $this->dependencyLinks()
            ->where('blocker_type', $blocker->getMorphClass())
            ->where('blocker_id', $blocker->getKey())
            ->delete();

        $this->unsetRelation('dependencyLinks');
    }

    /**
     * Whether adding the given blocker would point an item at itself or close a
     * dependency cycle (the blocker already depends on this item).
     */
    public function wouldCreateCycleWith(Task $blocker): bool
    {
        return $blocker->is($this) || $blocker->dependsOn($this);
    }

    /**
     * Whether this item depends — directly or transitively — on the given one.
     */
    public function dependsOn(Task $other): bool
    {
        /** @var array<string, true> $visited */
        $visited = [];

        /** @var array<int, Task> $queue */
        $queue = [$this];

        while ($queue !== []) {
            $node = array_shift($queue);

            foreach ($node->blockers() as $blocker) {
                if ($blocker->is($other)) {
                    return true;
                }

                $key = $blocker->getMorphClass().':'.$blocker->getKey();

                if (isset($visited[$key])) {
                    continue;
                }

                $visited[$key] = true;
                $queue[] = $blocker;
            }
        }

        return false;
    }
}
