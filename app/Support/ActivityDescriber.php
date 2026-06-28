<?php

namespace App\Support;

use App\Enums\CancelReason;
use App\Enums\Priority;
use App\Enums\RelationshipType;
use App\Enums\Status;
use App\Models\Activity;
use Illuminate\Support\Arr;

/**
 * Turns an {@see Activity} into a localized, human-readable description line
 * (e.g. "changed status from To do to Done"). Shared by the activity feed, the
 * comment composer preview, and the posted-comment reference card so the
 * wording stays identical wherever an entry is shown.
 */
class ActivityDescriber
{
    /**
     * Build the description line for a single activity.
     */
    public static function describe(Activity $activity): string
    {
        $newValues = (array) json_decode((string) $activity->new_value, true);
        $oldValues = (array) json_decode((string) $activity->old_value, true);

        return match ($activity->action) {
            'created' => __('created this'),
            'status_changed' => __('changed status from :old to :new', [
                'old' => self::statusLabel($activity->old_value),
                'new' => self::statusLabel($activity->new_value),
            ]),
            'priority_changed' => __('changed priority from :old to :new', [
                'old' => self::priorityLabel($activity->old_value),
                'new' => self::priorityLabel($activity->new_value),
            ]),
            'type_changed' => self::typeDescription($activity->old_value, $activity->new_value),
            'assignee_changed' => self::assigneeDescription($newValues, $oldValues),
            'dependency_changed' => self::dependencyDescription($newValues, $oldValues),
            'tags_changed' => self::tagDescription($newValues, $oldValues),
            'tag_renamed' => __('renamed the tag :old to :new', ['old' => (string) $activity->old_value, 'new' => (string) $activity->new_value]),
            'tag_recolored' => __('changed the color of the tag :name', ['name' => (string) ($newValues['name'] ?? '')]),
            'tag_deleted' => __('deleted the tag :name', ['name' => (string) $activity->old_value]),
            'tag_merged' => __('merged the tag :old into :new', ['old' => (string) $activity->old_value, 'new' => (string) $activity->new_value]),
            'parent_changed' => self::parentDescription($activity->old_value, $activity->new_value),
            'canceled' => self::cancellationDescription($newValues),
            'reopened' => __('reopened this'),
            'archived' => __('archived this'),
            'unarchived' => __('restored this from the archive'),
            'commented' => __('added a comment'),
            'comment_deleted' => ($activity->new_value ?? '') !== ''
                ? __('deleted a comment: :reason', ['reason' => (string) $activity->new_value])
                : __('deleted a comment'),
            default => $activity->action,
        };
    }

    /**
     * Describe a re-parent move from the old and new parent references (either
     * may be null, meaning the top level).
     */
    private static function parentDescription(?string $old, ?string $new): string
    {
        return match (true) {
            $new !== null && $old !== null => __('moved this from :old to :new', ['old' => $old, 'new' => $new]),
            $new !== null => __('moved this under :new', ['new' => $new]),
            default => __('moved this to the top level'),
        };
    }

    /**
     * Describe a task-type change from the old and new type names (either may be
     * null — set from untyped, or cleared to untyped).
     */
    private static function typeDescription(?string $old, ?string $new): string
    {
        return match (true) {
            $new !== null && $old !== null => __('changed type from :old to :new', ['old' => $old, 'new' => $new]),
            $new !== null => __('set the type to :new', ['new' => $new]),
            default => __('cleared the type'),
        };
    }

    /**
     * Resolve a status value to its label, falling back to the raw value.
     */
    private static function statusLabel(?string $value): string
    {
        return Status::tryFrom((string) $value)?->label() ?? (string) $value;
    }

    /**
     * Resolve a priority value to its label, falling back to the raw value.
     */
    private static function priorityLabel(?string $value): string
    {
        return Priority::tryFrom((int) $value)?->label() ?? (string) $value;
    }

    /**
     * Describe an assignee change from the added and removed names.
     *
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     */
    private static function assigneeDescription(array $added, array $removed): string
    {
        $conjunction = ' '.__('and').' ';
        $addedList = Arr::join($added, ', ', $conjunction);
        $removedList = Arr::join($removed, ', ', $conjunction);

        return match (true) {
            $added !== [] && $removed !== [] => __('assigned :added, unassigned :removed', ['added' => $addedList, 'removed' => $removedList]),
            $added !== [] => __('assigned :users', ['users' => $addedList]),
            $removed !== [] => __('unassigned :users', ['users' => $removedList]),
            default => __('updated the assignees'),
        };
    }

    /**
     * Describe a tag change from the added and removed tags.
     *
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     */
    private static function tagDescription(array $added, array $removed): string
    {
        $conjunction = ' '.__('and').' ';
        $addedList = Arr::join($added, ', ', $conjunction);
        $removedList = Arr::join($removed, ', ', $conjunction);

        return match (true) {
            $added !== [] && $removed !== [] => __('added the tags :added, removed :removed', ['added' => $addedList, 'removed' => $removedList]),
            $added !== [] => __('added the tags :tags', ['tags' => $addedList]),
            $removed !== [] => __('removed the tags :tags', ['tags' => $removedList]),
            default => __('updated the tags'),
        };
    }

    /**
     * Describe a cancellation from its reason and optional message snapshot.
     *
     * @param  array<string, string|null>  $payload
     */
    private static function cancellationDescription(array $payload): string
    {
        $reason = CancelReason::tryFrom((string) ($payload['reason'] ?? ''))?->label();
        $message = $payload['message'] ?? null;

        return match (true) {
            $reason !== null && $message => __('canceled this as :reason — :message', ['reason' => $reason, 'message' => $message]),
            $reason !== null => __('canceled this as :reason', ['reason' => $reason]),
            default => __('canceled this'),
        };
    }

    /**
     * Describe a dependency change from the added or removed link.
     *
     * @param  array<string, string>  $added
     * @param  array<string, string>  $removed
     */
    private static function dependencyDescription(array $added, array $removed): string
    {
        $linked = ($added['direction'] ?? null) !== null;
        $payload = $linked ? $added : $removed;
        $resolved = RelationshipType::fromKeyword($payload['direction'] ?? '');

        if ($resolved === null) {
            return __('updated the dependencies');
        }

        [$type, $asSubject] = $resolved;

        return $type->activityDescription($linked, $asSubject, $payload['reference'] ?? '');
    }
}
