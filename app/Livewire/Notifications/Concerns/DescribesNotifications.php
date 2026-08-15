<?php

namespace App\Livewire\Notifications\Concerns;

/**
 * Turns a notification's raw `action` into the wording the panel and the inbox
 * both show, and groups the actions into the categories the inbox filters by,
 * so the two surfaces cannot drift.
 */
trait DescribesNotifications
{
    /**
     * The inbox's activity-type filter: a category name to the actions it
     * covers. An action missing here is only ever matched by "all".
     *
     * @var array<string, list<string>>
     */
    public const array ACTION_CATEGORIES = [
        'mentions' => ['mentioned'],
        'comments' => ['commented', 'comment_deleted'],
        'progress' => ['created', 'status_changed', 'parent_changed'],
        'assignments' => ['assignee_changed'],
        'details' => ['priority_changed', 'type_changed', 'tags_changed'],
    ];

    /**
     * The short verb describing what a notification's underlying action did to
     * its subject (e.g. "commented on", "changed the status of"), shown before
     * the subject reference.
     */
    public function actionLabel(string $action): string
    {
        return match ($action) {
            'created' => __('created'),
            'status_changed' => __('changed the status of'),
            'priority_changed' => __('changed the priority of'),
            'type_changed' => __('changed the type of'),
            'assignee_changed' => __('updated the assignees of'),
            'tags_changed' => __('updated the tags of'),
            'parent_changed' => __('moved'),
            'commented' => __('commented on'),
            'comment_deleted' => __('deleted a comment on'),
            'mentioned' => __('mentioned you in'),
            default => __('updated'),
        };
    }

    /**
     * The translated label of an activity-type category.
     */
    public function categoryLabel(string $category): string
    {
        return match ($category) {
            'mentions' => __('Mentions'),
            'comments' => __('Comments'),
            'progress' => __('Progress'),
            'assignments' => __('Assignments'),
            'details' => __('Details'),
            default => __('All activity'),
        };
    }
}
