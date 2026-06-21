<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Assigns a per-parent sequential number (starting at 1) on creation.
 *
 * Implementing models must define:
 *  - protected string $scopedNumberColumn (e.g. 'task_number')
 *  - public function scopedNumberQuery(): Builder  (siblings sharing the same parent)
 *
 * The owning table must carry a unique `(parent_id, number)` constraint — it is the
 * source of truth that makes the optimistic retry below safe.
 */
trait HasScopedNumber
{
    /**
     * How many times to re-derive the number after losing a concurrent race before
     * giving up and surfacing the unique-constraint violation.
     */
    protected static int $scopedNumberMaxAttempts = 5;

    /**
     * Derive the scoped number and insert in the same call, retrying if a concurrent
     * creation grabbed the same number first.
     *
     * The previous implementation derived the number inside a self-contained
     * `DB::transaction` in the `creating` hook, which committed — releasing its
     * `lockForUpdate` row lock — before Eloquent ran the INSERT. Two concurrent
     * creations could then read the same max sibling and collide on the unique
     * `(parent, number)` constraint, surfacing a 500. We instead let that constraint
     * arbitrate and re-derive on collision. Notifications fired in the `created`
     * event are left outside any added transaction, so a row lock is never held
     * across the subscriber notification fan-out.
     *
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        // A number explicitly provided (e.g. by a seeder) is left untouched.
        if (! empty($this->{$this->scopedNumberColumn})) {
            return parent::performInsert($query);
        }

        for ($attempt = 1; ; $attempt++) {
            $this->{$this->scopedNumberColumn} = $this->nextScopedNumber();

            try {
                return parent::performInsert($query);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= static::$scopedNumberMaxAttempts) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The next sequential number for this model's parent scope.
     */
    protected function nextScopedNumber(): int
    {
        return (int) $this->scopedNumberQuery()
            ->orderByDesc($this->scopedNumberColumn)
            ->value($this->scopedNumberColumn) + 1;
    }

    /**
     * The query scoping siblings that share the same parent.
     */
    abstract public function scopedNumberQuery(): Builder;
}
