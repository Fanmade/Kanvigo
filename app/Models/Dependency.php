<?php

namespace App\Models;

use App\Enums\RelationshipType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A directed relationship link between two items. The {@see blocker} end is the
 * subject (outward) side and the {@see dependent} end is the object (inward)
 * side; the {@see $type} says how they relate. For the default "blocks" type the
 * blocker must be completed before the dependent. Both ends are polymorphic.
 *
 * @property int $id
 * @property string $dependent_type
 * @property int $dependent_id
 * @property string $blocker_type
 * @property int $blocker_id
 * @property RelationshipType $type
 */
class Dependency extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RelationshipType::class,
        ];
    }

    /**
     * Constrain to the link between two tasks, regardless of which is the blocker
     * and which is the dependent (a relationship is stored once, in one direction).
     *
     * @param  Builder<Dependency>  $query
     */
    #[Scope]
    protected function betweenTasks(Builder $query, Task $a, Task $b): void
    {
        $query->where(static fn (Builder $inner): Builder => $inner
            ->where('dependent_type', $a->getMorphClass())->where('dependent_id', $a->getKey())
            ->where('blocker_type', $b->getMorphClass())->where('blocker_id', $b->getKey()))
            ->orWhere(static fn (Builder $inner): Builder => $inner
                ->where('dependent_type', $b->getMorphClass())->where('dependent_id', $b->getKey())
                ->where('blocker_type', $a->getMorphClass())->where('blocker_id', $a->getKey()));
    }

    /**
     * The blocked item (a Task).
     *
     * @return MorphTo<Model, $this>
     */
    public function dependent(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The blocking item that must be completed first (a Task).
     *
     * @return MorphTo<Model, $this>
     */
    public function blocker(): MorphTo
    {
        return $this->morphTo();
    }
}
