<?php

namespace App\Concerns;

use App\Enums\RelationshipType;
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
            if (! $link->type->isBlocking()) {
                continue;
            }

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
            if (! $link->type->isBlocking()) {
                continue;
            }

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
     * This item's relationships grouped by keyword (from its perspective), as the
     * references of the related tasks. Every keyword is present, mapping to a
     * possibly-empty list — e.g. `['blocked_by' => ['KAN-3'], 'blocks' => [], …]`.
     *
     * @return array<string, list<string>>
     */
    public function relationshipReferences(): array
    {
        /** @var array<string, list<string>> $grouped */
        $grouped = array_fill_keys(RelationshipType::keywords(), []);

        foreach ($this->dependencyLinks as $link) {
            $related = $link->blocker;

            if ($related instanceof Task) {
                $grouped[$link->type->keyword(asSubject: false)][] = $related->reference;
            }
        }

        foreach ($this->dependentLinks as $link) {
            $related = $link->dependent;

            if ($related instanceof Task) {
                $grouped[$link->type->keyword(asSubject: true)][] = $related->reference;
            }
        }

        return $grouped;
    }

    /**
     * Record a typed relationship between this item and the given one, creating
     * the link if it does not already exist. `$asSubject` says whether this item
     * is the subject (outward) end — e.g. for {@see RelationshipType::Blocks} the
     * subject blocks the object. Symmetric types ignore it and are stored
     * canonically so the same link is never stored twice.
     *
     * @throws InvalidArgumentException when the link would be a self-relationship or close a blocking cycle.
     */
    public function addRelationship(Task $related, RelationshipType $type, bool $asSubject): Dependency
    {
        if ($related->is($this)) {
            throw new InvalidArgumentException('A relationship cannot link an item to itself.');
        }

        if ($type->isSymmetric()) {
            [$dependent, $blocker] = $this->getKey() <= $related->getKey()
                ? [$this, $related]
                : [$related, $this];
        } elseif ($asSubject) {
            [$dependent, $blocker] = [$related, $this];
        } else {
            [$dependent, $blocker] = [$this, $related];
        }

        if ($type->isBlocking() && $dependent->wouldCreateCycleWith($blocker)) {
            throw new InvalidArgumentException('A dependency cannot create a cycle.');
        }

        $dependency = Dependency::firstOrCreate([
            'dependent_type' => $dependent->getMorphClass(),
            'dependent_id' => $dependent->getKey(),
            'blocker_type' => $blocker->getMorphClass(),
            'blocker_id' => $blocker->getKey(),
            'type' => $type->value,
        ]);

        $this->unsetRelation('dependencyLinks');
        $this->unsetRelation('dependentLinks');

        return $dependency;
    }

    /**
     * Record that this item is blocked by the given one, creating the link if it
     * does not already exist.
     *
     * @throws InvalidArgumentException when the link would be a self-dependency or close a cycle.
     */
    public function addBlocker(Task $blocker): Dependency
    {
        return $this->addRelationship($blocker, RelationshipType::Blocks, asSubject: false);
    }

    /**
     * Remove the blocking link recording that this item is blocked by the given
     * one. Non-blocking relationships between the two are left untouched.
     */
    public function removeBlocker(Task $blocker): void
    {
        $this->dependencyLinks()
            ->where('blocker_type', $blocker->getMorphClass())
            ->where('blocker_id', $blocker->getKey())
            ->where('type', RelationshipType::Blocks->value)
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
