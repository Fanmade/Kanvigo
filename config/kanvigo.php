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
    | Icon picker
    |--------------------------------------------------------------------------
    |
    | The curated set of Heroicons offered when picking an icon for a task type
    | or a tag — relevant to classifying work, rather than the full Heroicon
    | catalog. Read through App\Support\IconCatalog::available(). Extend the list
    | here to offer more choices in the pickers.
    |
    */

    'icons' => [
        'tag', 'sparkles', 'bug-ant', 'wrench-screwdriver', 'wrench', 'bolt',
        'beaker', 'book-open', 'shield-check', 'rocket-launch', 'paint-brush',
        'cog-6-tooth', 'flag', 'star', 'exclamation-triangle',
        'arrows-right-left', 'arrow-path-rounded-square', 'cube-transparent',
        'magnifying-glass', 'light-bulb', 'circle-stack',
        'chat-bubble-left-right', 'server-stack', 'fire', 'link', 'document-text',
        'arrow-trending-up', 'bell', 'command-line', 'credit-card', 'computer-desktop',
        'device-phone-mobile', 'device-tablet', 'finger-print', 'information-circle',
        'question-mark-circle', 'chart-pie', 'cake', 'clipboard-document-list',
        'key', 'language', 'lifebuoy', 'lock-closed', 'map-pin', 'moon',
        'paper-airplane', 'scale', 'scissors', 'share', 'shopping-bag', 'shopping-cart',
        'signal', 'speaker-wave', 'truck', 'user-group', 'user-circle', 'view-columns',
        'x-mark', 'arrows-up-down', 'hand-raised', 'home', 'envelope-open', 'envelope',
        'at-symbol', 'phone', 'building-office', 'building-library', 'viewfinder-circle',
        'ticket', 'sun',
    ],

];
