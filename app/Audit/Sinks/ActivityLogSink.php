<?php

namespace App\Audit\Sinks;

use App\Concerns\LogsActivity;
use App\Contracts\Subscribable;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ItemActivity;
use App\Support\BoardCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Kanvigo\Audit\Contracts\AuditSink;
use Kanvigo\Audit\Contracts\SinkPolicy;

/**
 * The default sink: the product activity feed. Accepts exactly the classic
 * feed-worthy content events on a LogsActivity subject (Task/Project) and
 * owns the feed's persistence and side effects — the Activity row, the
 * board-cache bust and the subscriber notifications. Everything else
 * (authn, authz, token, security events) is rejected here and flows only
 * to ledger/transport sinks.
 */
class ActivityLogSink implements AuditSink
{
    /**
     * The feed-worthy content actions — the exact set the activity log has
     * always recorded, so a default install behaves identically. New event
     * types must NOT be added here casually: anything accepted busts board
     * caches and notifies subscribers.
     */
    private const array FEED_ACTIONS = [
        'created',
        'status_changed',
        'priority_changed',
        'type_changed',
        'parent_changed',
        'assignee_changed',
        'tags_changed',
        'dependency_changed',
        'commented',
        'comment_deleted',
        'attachment_added',
        'attachment_removed',
        'archived',
        'unarchived',
        'canceled',
        'reopened',
        'tag_renamed',
        'tag_recolored',
        'tag_deleted',
        'tag_merged',
    ];

    /**
     * Feed actions that don't change how a board card renders, so they never
     * need to bust the board cache.
     */
    private const array BOARD_IRRELEVANT_ACTIONS = ['commented', 'comment_deleted'];

    public function accepts(AuditEvent $event): bool
    {
        return $event->category === AuditCategory::Content
            && $event->subjectType !== null
            && in_array($event->action, self::FEED_ACTIONS, true)
            && in_array(LogsActivity::class, class_uses_recursive($event->subjectType), true);
    }

    public function record(AuditEvent $event): void
    {
        $subject = $this->resolveSubject($event);

        $activity = $this->persist($event, $subject);

        // Tag/assignee/dependency changes don't touch the task row (so the
        // saved() board-cache hook doesn't fire) but do change how its card
        // renders — invalidate here. Comment activity (added or deleted)
        // doesn't appear on the board, so it never needs to bust the cache.
        if ($subject instanceof Task && ! in_array($event->action, self::BOARD_IRRELEVANT_ACTIONS, true)) {
            BoardCache::touch($subject->project_id);
        }

        if ($subject instanceof Subscribable) {
            $this->notifySubscribers($subject, $activity, $event->actorId);
        }
    }

    public function policy(): SinkPolicy
    {
        return SinkPolicy::sync();
    }

    /**
     * The subject the event was recorded on, refetched by morph type and id.
     * Null when it no longer exists — the feed row is still written (the
     * trail outlives its subject), but cache and notification side effects
     * are skipped.
     */
    protected function resolveSubject(AuditEvent $event): ?Model
    {
        /** @var class-string<Model> $type */
        $type = $event->subjectType;

        return $type::query()->find($event->subjectId);
    }

    /**
     * Write the Activity feed row the event maps to.
     */
    protected function persist(AuditEvent $event, ?Model $subject): Activity
    {
        $activity = new Activity([
            'user_id' => $event->actorId,
            'token_name' => $event->context?->tokenName,
            'action' => $event->action,
            'field' => $event->metadata['field'] ?? null,
            'old_value' => $event->metadata['old'] ?? null,
            'new_value' => $event->metadata['new'] ?? null,
        ]);

        $activity->subject_type = $event->subjectType;
        $activity->subject_id = (int) $event->subjectId;

        if ($event->occurredAt !== null) {
            $activity->created_at = Carbon::instance($event->occurredAt);
        }

        $activity->save();

        return $activity->setRelation('subject', $subject);
    }

    /**
     * Notify the subject's subscribers (excluding the actor) about an update.
     */
    protected function notifySubscribers(Subscribable $subject, Activity $activity, int|string|null $actorId): void
    {
        $subject->notificationAudience()
            ->unique('id')
            ->reject(static fn (User $user): bool => $user->id === $actorId)
            ->each(static fn (User $user) => $user->notify(new ItemActivity($activity)));
    }
}
