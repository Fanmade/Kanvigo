<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;

trait ServesScopedAttachments
{
    /**
     * Ensure the attachment is being requested under its owning project and the
     * current user may view that project, otherwise abort.
     */
    protected function authorizeScopedAttachment(string $shortName, Attachment $attachment): void
    {
        abort_unless($attachment->ownerProject()?->short_name === $shortName, 404);

        Gate::authorize('view', $attachment);
    }
}
