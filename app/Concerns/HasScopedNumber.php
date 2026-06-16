<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Assigns a per-parent sequential number (starting at 1) on creation.
 *
 * Implementing models must define:
 *  - protected string $scopedNumberColumn (e.g. 'story_number')
 *  - public function scopedNumberQuery(): Builder  (siblings sharing the same parent)
 */
trait HasScopedNumber
{
    public static function bootHasScopedNumber(): void
    {
        static::creating(static function (Model $model): void {
            /** @var Model&self $model */
            if (! empty($model->{$model->scopedNumberColumn})) {
                return;
            }

            DB::transaction(static function () use ($model): void {
                $next = (int) $model->scopedNumberQuery()->lockForUpdate()->max($model->scopedNumberColumn) + 1;

                $model->{$model->scopedNumberColumn} = $next;
            });
        });
    }

    /**
     * The query scoping siblings that share the same parent.
     */
    abstract public function scopedNumberQuery(): Builder;
}
