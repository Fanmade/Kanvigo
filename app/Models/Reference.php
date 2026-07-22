<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A directed cross-reference between two items (KAN-439): the {@see source} item
 * references the {@see target} item. Both ends are polymorphic across tasks and
 * docs. Unlike a {@see Dependency} this carries no type and is not a blocker —
 * it is pure navigation, and may legitimately be circular. The backlink is this
 * same row read from the target's side, so a link is stored once.
 *
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property string $target_type
 * @property int $target_id
 */
class Reference extends Model
{
    protected $table = 'item_references';

    protected $guarded = [];

    /**
     * The item that makes the reference.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The item being referenced.
     *
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
