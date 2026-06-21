<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A directed dependency link: the {@see dependent} item is blocked by the
 * {@see blocker} item, which must be completed first. Both ends are polymorphic.
 *
 * @property int $id
 * @property string $dependent_type
 * @property int $dependent_id
 * @property string $blocker_type
 * @property int $blocker_id
 */
class Dependency extends Model
{
    protected $guarded = [];

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
