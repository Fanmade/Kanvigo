<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attachment Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk where uploaded attachments are stored. Any disk
    | configured in config/filesystems.php may be used. Each attachment also
    | records the disk it was stored on, so changing this value never breaks
    | access to files that were uploaded under a previous configuration.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Storage Directory
    |--------------------------------------------------------------------------
    |
    | The directory, relative to the disk root, where attachments are stored.
    |
    */

    'directory' => env('ATTACHMENTS_DIRECTORY', 'attachments'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Upload Size
    |--------------------------------------------------------------------------
    |
    | The maximum allowed size for a single uploaded attachment, in kilobytes.
    | This is enforced on top of Livewire's own temporary upload limit, so keep
    | the two in sync (config/livewire.php "temporary_file_upload.rules").
    |
    */

    'max_size' => (int) env('ATTACHMENTS_MAX_SIZE', 12288),

    /*
    |--------------------------------------------------------------------------
    | Ghostscript Binary
    |--------------------------------------------------------------------------
    |
    | The Ghostscript executable used to rasterize the first page of a PDF when
    | generating a preview thumbnail. When it is missing or rendering fails, the
    | attachment simply falls back to a generic icon.
    |
    */

    'ghostscript' => env('ATTACHMENTS_GHOSTSCRIPT', 'gs'),

];
