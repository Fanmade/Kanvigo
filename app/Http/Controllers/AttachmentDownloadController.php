<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesScopedAttachments;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentDownloadController extends Controller
{
    use ServesScopedAttachments;

    /**
     * Stream an attachment back to an authorized user.
     */
    public function __invoke(string $short_name, Attachment $attachment): StreamedResponse
    {
        $this->authorizeScopedAttachment($short_name, $attachment);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->name);
    }
}
