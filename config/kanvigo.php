<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tasks
    |--------------------------------------------------------------------------
    |
    | Tasks nest under one another to form a tree. "max_depth" caps how many
    | levels deep that tree may grow, counting the root as level 1 (the default
    | of 3 allows root -> child -> grandchild). The limit is enforced when a task
    | is created with a parent and when an existing subtree is re-parented.
    |
    */

    'tasks' => [
        'max_depth' => (int) env('KANVIGO_TASK_MAX_DEPTH', 3),

        /*
         * Default number of days a task may sit in "Done" before it is
         * auto-archived off the board. Projects may override this (their
         * "auto_archive_days"); 0 disables auto-archiving.
         */
        'auto_archive_days' => (int) env('KANVIGO_AUTO_ARCHIVE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live updates
    |--------------------------------------------------------------------------
    |
    | How often auto-refreshing views (the boards, the task page) poll for
    | changes while the viewer has "Live updates" enabled, in seconds.
    |
    */

    'live_updates' => [
        'interval_seconds' => (int) env('KANVIGO_LIVE_UPDATES_INTERVAL', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    |
    | An export that inlines its images as Base64 data URIs downscales them
    | first: "image_max_edge" caps the longest edge in pixels — large enough to
    | read a screenshot or a diagram, which is the point of inlining. Once the
    | encoded images together exceed "inline_budget" bytes, the rest of them
    | degrade to plain links, so a picture-heavy export gets big rather than
    | failing.
    |
    */

    'export' => [
        'image_max_edge' => (int) env('KANVIGO_EXPORT_IMAGE_MAX_EDGE', 1024),
        'inline_budget' => (int) env('KANVIGO_EXPORT_INLINE_BUDGET', 5 * 1024 * 1024),

        /*
         * How many items (tasks plus docs) a whole-project export may cover.
         * The archive is built in the request, so this is the line past which
         * that stops being reasonable: above it the export is refused with a
         * clear message rather than tying up a web worker.
         */
        'max_project_items' => (int) env('KANVIGO_EXPORT_MAX_PROJECT_ITEMS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity feed retention
    |--------------------------------------------------------------------------
    |
    | The product activity feed is append-only: every audited content action adds
    | a row. The daily "activity:prune" command deletes entries older than this
    | many days, which also drops the comment links pointing at them. Set to 0 —
    | the default — to keep the feed forever.
    |
    | The "what did I miss" marker is a timestamp on the reader, not a feed row,
    | so pruning never disturbs it.
    |
    */

    'activity' => [
        'retention_days' => (int) env('KANVIGO_ACTIVITY_RETENTION_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification retention
    |--------------------------------------------------------------------------
    |
    | How many days a notification is kept once the reader is done with it —
    | dismissed, or merely read. Both are pruned by the daily "model:prune";
    | a notification that is still unread is kept however old it is, so nothing
    | addressed at someone disappears before they have seen it.
    |
    */

    'notifications' => [
        'retention_days' => (int) env('KANVIGO_NOTIFICATION_RETENTION_DAYS', 30),
    ],

];
