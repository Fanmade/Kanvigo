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

];
