<?php

namespace App\Http\Controllers\Concerns;

use App\Audit\AccessAudit;
use App\Models\Attachment;
use App\Support\Facades\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ServesScopedAttachments
{
    /**
     * Authorize an attachment request. Project/Task attachments are served under
     * their owning project's short name (a mismatch — or a projectless owner —
     * 404s). Note attachments are projectless and served with a null short name;
     * access is gated purely by the policy, which cascades to the note.
     */
    protected function authorizeScopedAttachment(?string $shortName, Attachment $attachment): void
    {
        if ($shortName !== null) {
            abort_unless($attachment->ownerProject()?->short_name === $shortName, 404);
        }

        Gate::authorize('view', $attachment);
    }

    /**
     * Stream the attachment inline (for embedded images, etc.).
     */
    protected function streamAttachment(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->name);
    }

    /**
     * Stream the attachment as a download.
     */
    protected function downloadAttachment(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        Audit::record(AccessAudit::attachmentDownloaded($attachment));

        return $disk->download($attachment->path, $attachment->name);
    }

    /**
     * Stream the attachment's preview thumbnail.
     */
    protected function streamThumbnail(Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->thumbnail_path !== null, 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->thumbnail_path), 404);

        return $disk->response($attachment->thumbnail_path);
    }
}
