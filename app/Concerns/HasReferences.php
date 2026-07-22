<?php

namespace App\Concerns;

use App\Contracts\Referenceable;
use App\Models\Doc;
use App\Models\Reference;
use App\Models\Task;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Adds cross-references to an item (a Task or a Doc). An item references the
 * items under {@see references()} and is referenced back by those under
 * {@see referencedBy()} (its backlinks). References are directed and polymorphic;
 * a link is stored once — the backlink is the same row read from the other side.
 *
 * Independent of the dependency/blocker system: a reference is not a blocker and
 * has no cycle guard (references may legitimately be circular). Authorizing who
 * may link two items is the caller's job, as with {@see HasDependencies} — this
 * concern only guards against self-references.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Referenceable
 */
trait HasReferences
{
    /**
     * Remove an item's reference links — in both directions — when it is deleted,
     * so no link is left pointing at a missing item.
     */
    public static function bootHasReferences(): void
    {
        static::deleting(static function (Model $model): void {
            $morph = $model->getMorphClass();
            $key = $model->getKey();

            Reference::query()
                ->where(static fn (Builder $query): Builder => $query->where('source_type', $morph)->where('source_id', $key))
                ->orWhere(static fn (Builder $query): Builder => $query->where('target_type', $morph)->where('target_id', $key))
                ->delete();
        });
    }

    /**
     * The links where this item is the source (pointing at the items it references).
     *
     * @return MorphMany<Reference, $this>
     */
    public function outgoingReferences(): MorphMany
    {
        return $this->morphMany(Reference::class, 'source');
    }

    /**
     * The links where this item is the target (pointing at the items that
     * reference it — its backlinks).
     *
     * @return MorphMany<Reference, $this>
     */
    public function incomingReferences(): MorphMany
    {
        return $this->morphMany(Reference::class, 'target');
    }

    /**
     * The items this one references.
     *
     * @return Collection<int, Model&Referenceable>
     */
    public function references(): Collection
    {
        return $this->relatedItems($this->outgoingReferences->loadMissing('target'), 'target');
    }

    /**
     * The items that reference this one (its backlinks).
     *
     * @return Collection<int, Model&Referenceable>
     */
    public function referencedBy(): Collection
    {
        return $this->relatedItems($this->incomingReferences->loadMissing('source'), 'source');
    }

    /**
     * Record that this item references the given one, creating the link if it
     * does not already exist.
     *
     * @throws InvalidArgumentException when the link would reference the item itself.
     */
    public function addReference(Model&Referenceable $target): Reference
    {
        if ($target->is($this)) {
            throw new InvalidArgumentException('An item cannot reference itself.');
        }

        $reference = Reference::firstOrCreate([
            'source_type' => $this->getMorphClass(),
            'source_id' => $this->getKey(),
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
        ]);

        $this->unsetRelation('outgoingReferences');

        return $reference;
    }

    /**
     * Remove this item's reference to the given one. The reverse link (the target
     * referencing this item), if any, is left untouched.
     */
    public function removeReference(Model&Referenceable $target): void
    {
        $this->outgoingReferences()
            ->where('target_type', $target->getMorphClass())
            ->where('target_id', $target->getKey())
            ->delete();

        $this->unsetRelation('outgoingReferences');
    }

    /**
     * The eager-load spec for an item's reference targets and sources — the linked
     * tasks/docs and the project each needs to resolve its reference. Usable with
     * both with() and loadMissing() to keep reference resolution N+1-free.
     *
     * @return array<string, Closure>
     */
    public static function referenceItemsEagerLoad(): array
    {
        $withProject = static fn (MorphTo $morphTo) => $morphTo->morphWith([
            Task::class => ['project'],
            Doc::class => ['project'],
        ]);

        return [
            'outgoingReferences.target' => $withProject,
            'incomingReferences.source' => $withProject,
        ];
    }

    /**
     * Pluck the related model from each link, keeping only referenceable items.
     *
     * @param  Collection<int, Reference>  $links
     * @return Collection<int, Model&Referenceable>
     */
    protected function relatedItems(Collection $links, string $relation): Collection
    {
        return $links
            ->map(static fn (Reference $link): ?Model => $link->getRelation($relation))
            ->filter(static fn (?Model $item): bool => $item instanceof Referenceable)
            ->values();
    }
}
