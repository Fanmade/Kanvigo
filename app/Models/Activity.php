<?php

namespace App\Models;

use App\Concerns\HasScopedNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $project_id
 * @property string|null $token_name
 * @property string $subject_type
 * @property int $subject_id
 * @property int $sequence
 * @property string $action
 * @property string|null $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read Project|null $project
 * @property-read string|null $reference
 */
#[Fillable(['user_id', 'token_name', 'action', 'field', 'old_value', 'new_value'])]
class Activity extends Model
{
    use HasScopedNumber;

    public const UPDATED_AT = null;

    /**
     * The per-subject ordinal column ("the Nth entry recorded for this subject").
     */
    protected string $scopedNumberColumn = 'sequence';

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Restrict a query to the activity the given user may read.
     *
     * Authorization belongs in the query, not behind the pagination: filtering
     * a page after it has been fetched shortens it silently, so a feed page
     * would shrink wherever a foreign project's rows fall in it. The denormalized
     * project_id (KAN-550) makes it a plain `whereIn` — no polymorphic join.
     *
     * Where visibility is finer than the project it is layered on top: a doc
     * draft is readable only by those who may edit docs in its project, so the
     * activity recorded on it is too.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereIn('project_id', $user->projectIdsWithPermission('view-activity-log'))
            ->whereNot(static function (Builder $hidden) use ($user): void {
                $hidden->where('subject_type', (new Doc)->getMorphClass())
                    ->whereNotIn('project_id', $user->projectIdsWithPermission('edit-doc'))
                    ->whereExists(static fn (QueryBuilder $doc): QueryBuilder => $doc
                        ->from('docs')
                        ->whereColumn('docs.id', 'activities.subject_id')
                        ->where('docs.is_public', false));
            });
    }

    /**
     * Siblings sharing the same subject — the scope the {@see $sequence} numbers
     * within (see {@see HasScopedNumber}).
     *
     * @return Builder<static>
     */
    public function scopedNumberQuery(): Builder
    {
        return static::query()
            ->where('subject_type', $this->subject_type)
            ->where('subject_id', $this->subject_id);
    }

    /**
     * The portable, self-describing reference for this entry, e.g. "KAN-42-log-2"
     * for the 2nd activity recorded on task KAN-42. Null for subjects that don't
     * expose a reference (only task-subject activities do today).
     */
    public function getReferenceAttribute(): ?string
    {
        $subject = $this->subject;

        return $subject instanceof Task
            ? $subject->reference.'-log-'.$this->sequence
            : null;
    }

    /**
     * A deep link to this entry in its subject's activity feed: the "?log=N"
     * query forces the (lazy, collapsed) feed open and the "#log-N" fragment
     * scrolls to the row. Null for subjects without a per-entry reference.
     */
    public function deepLinkUrl(): ?string
    {
        $subject = $this->subject;

        if (! $subject instanceof Task) {
            return null;
        }

        return route('task.show', [
            'short_name' => $subject->project->short_name,
            'task_number' => $subject->task_number,
        ]).'?log='.$this->sequence.'#log-'.$this->sequence;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The project the entry belongs to, denormalized onto the row so a
     * cross-project feed can filter and label without a polymorphic join.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The comments that reference this entry.
     *
     * @return BelongsToMany<Comment, $this>
     */
    public function comments(): BelongsToMany
    {
        return $this->belongsToMany(Comment::class)->withTimestamps();
    }

    /**
     * Encode a structured payload — an assignee/tag name list, or a dependency,
     * cancellation or tag-recolor snapshot — for storage in old_value/new_value.
     * Returns null for an empty or absent list, so "nothing on this side" stays
     * null rather than the string "[]".
     *
     * This is the single encoding contract for the JSON-shaped activity values;
     * scalar values (a status, a name, a reason) are stored as-is and read back
     * from old_value/new_value directly. {@see decodeValue()} is its inverse.
     *
     * @param  array<mixed>|null  $value
     */
    public static function encodeValue(?array $value): ?string
    {
        return $value === null || $value === []
            ? null
            : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a structured payload previously stored by {@see encodeValue()}.
     * Returns [] for null, blank or non-JSON input, so callers get a predictable
     * array shape.
     *
     * @return array<mixed>
     */
    public static function decodeValue(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return (array) json_decode($value, true);
    }
}
