<?php

namespace App\Models;

use App\Jobs\SyncVariableUsages;
use Database\Factories\VariableUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One occurrence of a variable name inside a project's authored content — a task
 * description, doc body, comment or project description.
 *
 * Derived state: rows are written from content by {@see SyncVariableUsages}
 * and can be rebuilt at any time with `variables:reindex`. They are keyed on the
 * name rather than a variable id, so a usage of a name the project does not
 * define is representable — that is how an unknown name surfaces.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $usable_type
 * @property int $usable_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'name', 'usable_type', 'usable_id'])]
class VariableUsage extends Model
{
    /** @use HasFactory<VariableUsageFactory> */
    use HasFactory;

    /**
     * The item whose content uses the name.
     *
     * @return MorphTo<Model, $this>
     */
    public function usable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The page this usage leads to: the item itself for a task, doc or project,
     * and the commented-on item for a comment — a comment has no page of its own.
     * Null when the item has since been deleted, or is of a kind with nowhere to
     * link (the index is derived state and may lag behind either).
     */
    public function page(): Project|Task|Doc|null
    {
        $item = $this->usable;

        if ($item instanceof Comment) {
            $item = $item->commentable;
        }

        return $item instanceof Task || $item instanceof Doc || $item instanceof Project ? $item : null;
    }

    /**
     * The project whose namespace the name belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
