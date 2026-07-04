<?php

namespace App\Concerns;

use App\Audit\Sinks\ActivityLogSink;
use App\Enums\RelationshipType;
use App\Models\Activity;
use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;

/**
 * Marks a model as a subject of the product activity feed and provides the
 * emit-side helpers. Events are emitted through the single Audit::record()
 * point; the feed's persistence and side effects (Activity row, board-cache
 * bust, subscriber notifications) live in
 * {@see ActivityLogSink}, which accepts exactly the
 * feed-worthy content events on models carrying this trait.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(
            static function (Model $model): void {
                /** @var Model&self $model */
                Audit::record($model->contentAuditEvent('created'));
            });
    }

    /**
     * The audit-trail entries recorded for this model.
     *
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest();
    }

    /**
     * Build a feed-worthy content audit event for this model, ready to emit
     * via Audit::record(). Field and old/new values travel in the event
     * metadata under the conventional "field"/"old"/"new" keys.
     */
    public function contentAuditEvent(string $action, ?string $field = null, ?string $oldValue = null, ?string $newValue = null): AuditEvent
    {
        return AuditEvent::make($action, AuditCategory::Content)
            ->withSubject($this->getMorphClass(), $this->getKey())
            ->withMetadata(array_filter(
                ['field' => $field, 'old' => $oldValue, 'new' => $newValue],
                static fn (?string $value): bool => $value !== null,
            ));
    }

    /**
     * Record an assignee change, capturing the names of the users added and
     * removed as a JSON snapshot. Names are stored (rather than IDs) so the
     * trail reflects who was assigned at the time, even after a later rename or
     * deletion. Records nothing when nothing actually changed.
     *
     * @param  array<int, int|string>  $attachedIds  user IDs newly assigned
     * @param  array<int, int|string>  $detachedIds  user IDs unassigned
     */
    public function recordAssigneeChange(array $attachedIds, array $detachedIds): void
    {
        if ($attachedIds === [] && $detachedIds === []) {
            return;
        }

        $names = User::query()
            ->whereIn('id', array_merge($attachedIds, $detachedIds))
            ->pluck('name', 'id');

        $resolve = static fn (array $ids): array => collect($ids)
            ->map(static fn ($id) => $names[(int) $id] ?? null)
            ->filter()
            ->values()
            ->all();

        Audit::record($this->contentAuditEvent(
            'assignee_changed',
            'assignees',
            Activity::encodeValue($resolve($detachedIds)),
            Activity::encodeValue($resolve($attachedIds)),
        ));
    }

    /**
     * Record a tag change, capturing the names of the tags added and removed as
     * a JSON snapshot (added in new_value, removed in old_value). Records
     * nothing when nothing actually changed.
     *
     * @param  array<int, string>  $addedNames
     * @param  array<int, string>  $removedNames
     */
    public function recordTagChange(array $addedNames, array $removedNames): void
    {
        if ($addedNames === [] && $removedNames === []) {
            return;
        }

        Audit::record($this->contentAuditEvent(
            'tags_changed',
            'tags',
            Activity::encodeValue(array_values($removedNames)),
            Activity::encodeValue(array_values($addedNames)),
        ));
    }

    /**
     * Record a relationship link being added or removed. The relationship keyword
     * (e.g. "blocked_by", "duplicates", "relates") and the related reference are
     * captured from this item's perspective, so the trail can read "is now
     * blocked by KAN-3" or "no longer duplicates KAN-2".
     *
     * @param  bool  $linked  true when the link was added, false when removed
     * @param  string  $direction  the relationship keyword from this item's perspective ({@see RelationshipType::keywords()})
     * @param  string  $reference  the related item's reference
     */
    public function recordDependencyChange(bool $linked, string $direction, string $reference): void
    {
        $payload = Activity::encodeValue(['direction' => $direction, 'reference' => $reference]);

        Audit::record($this->contentAuditEvent(
            'dependency_changed',
            'dependencies',
            $linked ? null : $payload,
            $linked ? $payload : null,
        ));
    }
}
