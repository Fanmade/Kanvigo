<?php

namespace App\Concerns;

use App\Contracts\UsesVariables;
use App\Jobs\SyncVariableUsages;
use App\Models\VariableUsage;
use App\Support\VariableSyntax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Keeps the {@see VariableUsage} index in step with a model's rich-text content:
 * which variable names a task description, doc body, comment or project
 * description uses.
 *
 * The sync is queued, deliberately. Nothing on the render path reads the index —
 * substitution resolves names against the `variables` table directly — so a
 * delayed or failed job degrades a panel, never a page, and the save stays fast.
 * `variables:reindex` is the rebuild path when the queue does lose work.
 *
 * Removal is *not* queued: the rows are deleted with the item, since a job
 * cannot re-serialize a model that no longer exists, and leaving usages behind
 * would report deleted content as a live usage.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements UsesVariables
 */
trait IndexesVariableUsages
{
    public static function bootIndexesVariableUsages(): void
    {
        // created/updated rather than saved: `wasRecentlyCreated` stays true for
        // the instance's whole life, so a later save of the same object would
        // otherwise re-dispatch even when the content never moved.
        static::created(static function (UsesVariables&Model $model): void {
            if (filled($model->variableContent())) {
                SyncVariableUsages::dispatch($model);
            }
        });

        static::updated(static function (UsesVariables&Model $model): void {
            if ($model->wasChanged('description') || $model->wasChanged('body')) {
                SyncVariableUsages::dispatch($model);
            }
        });

        static::deleted(static function (UsesVariables&Model $model): void {
            $model->variableUsages()->delete();
        });

        // A soft-deleted item's rows go with it, so restoring has to put them
        // back. Registered through the generic hook because only soft-deletable
        // models declare a restored() helper — on the others it simply never fires.
        static::registerModelEvent('restored', static function (UsesVariables&Model $model): void {
            SyncVariableUsages::dispatch($model);
        });
    }

    /**
     * The recorded names this item's content uses.
     *
     * @return MorphMany<VariableUsage, $this>
     */
    public function variableUsages(): MorphMany
    {
        return $this->morphMany(VariableUsage::class, 'usable');
    }

    /**
     * Reconcile the index with the names the current content uses. Rows are
     * added and removed rather than replaced wholesale, so an unchanged usage
     * keeps its row (and its timestamps).
     */
    public function syncVariableUsages(): void
    {
        $projectId = $this->variableNamespaceProjectId();

        if ($projectId === null) {
            $this->variableUsages()->delete();

            return;
        }

        $used = VariableSyntax::namesIn($this->variableContent());
        $recorded = $this->variableUsages()->pluck('name')->all();

        $obsolete = array_diff($recorded, $used);

        if ($obsolete !== []) {
            $this->variableUsages()->whereIn('name', $obsolete)->delete();
        }

        foreach (array_diff($used, $recorded) as $name) {
            $this->variableUsages()->create(['project_id' => $projectId, 'name' => $name]);
        }

        // A row that predates a move between projects would otherwise keep the
        // old namespace; cheap to correct, and only ever touches stale rows.
        $this->variableUsages()->where('project_id', '!=', $projectId)->update(['project_id' => $projectId]);
    }

    /**
     * The project whose variable namespace this item's content resolves against,
     * or null when it has none (and therefore no usages to record).
     */
    abstract public function variableNamespaceProjectId(): ?int;

    /**
     * The rich-text content to scan. A model carries one of the description/body
     * columns; the other is simply absent.
     */
    public function variableContent(): ?string
    {
        foreach (['description', 'body'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
