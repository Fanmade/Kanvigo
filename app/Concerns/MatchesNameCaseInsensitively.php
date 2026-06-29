<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Case-insensitive lookup by `name`, matching the functional unique index
 * `(project_id, lower(name))` these models carry. Centralizes the `lower(name)`
 * comparison so every call site — and both Tag and TaskType — dedupes
 * identically. Callers trim the name first when leading/trailing space matters.
 *
 * @phpstan-require-extends Model
 */
trait MatchesNameCaseInsensitively
{
    /**
     * Constrain the query to rows whose `name` equals the given value,
     * case-insensitively.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function whereNameLower(Builder $query, string $name): void
    {
        $query->whereRaw('lower(name) = ?', [mb_strtolower($name)]);
    }
}
