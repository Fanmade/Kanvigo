<?php

namespace App\Http\Controllers\Concerns;

use App\Audit\AccessAudit;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Attachments\InlineSafeTypes;
use App\Support\Facades\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ServesScopedAttachments
{
    /**
     * Headers every response carrying attachment bytes gets.
     *
     * Uploaded files are attacker-controlled content served from the application's
     * own origin, so they are locked down as far as bytes can be: "nosniff" stops
     * the browser from upgrading a mislabelled file to something executable, and
     * the CSP denies the response every capability — no scripts, no subresources,
     * and sandboxed, so even a document type that slipped through cannot reach
     * the session it was opened with.
     *
     * @var array<string, string>
     */
    private const array HARDENED_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; sandbox",
    ];

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
     * Stream the attachment inline (for embedded images, etc.) — but only for a
     * type that is safe to render on our own origin. An SVG is a document, not a
     * picture: it may carry <script>, and served inline that script would run with
     * the viewing member's session — stored XSS that needs no more than
     * attachment-create rights on a single project. Anything outside the
     * allow-list is handed over as a download instead, which no browser executes
     * ({@see InlineSafeTypes}).
     */
    protected function streamAttachment(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $disposition = InlineSafeTypes::isSafe($attachment->mime_type)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        return $disk->response($attachment->path, $attachment->name, self::HARDENED_HEADERS, $disposition);
    }

    /**
     * Stream the attachment as a download. The actor is normally the
     * authenticated user, stamped onto the audit event automatically; a signed
     * download link has no authenticated user, so it names the user it was
     * issued for instead.
     */
    protected function downloadAttachment(Attachment $attachment, ?User $actor = null): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $event = AccessAudit::attachmentDownloaded($attachment);

        Audit::record($actor === null ? $event : $event->withActor($actor->getKey()));

        return $disk->download($attachment->path, $attachment->name, self::HARDENED_HEADERS);
    }

    /**
     * Stream the attachment's preview thumbnail.
     */
    protected function streamThumbnail(Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->thumbnail_path !== null, 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->thumbnail_path), 404);

        // Thumbnails are PNGs this application generated, but they are served
        // under the same hardening as the originals: nothing about an attachment
        // route should be able to execute.
        return $disk->response($attachment->thumbnail_path, headers: self::HARDENED_HEADERS);
    }
}
