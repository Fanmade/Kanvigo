<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesScopedAttachments;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentThumbnailController extends Controller
{
    use ServesScopedAttachments;

    /**
     * Stream an attachment's preview thumbnail inline to an authorized user.
     */
    public function __invoke(string $short_name, Attachment $attachment): StreamedResponse
    {
        $this->authorizeScopedAttachment($short_name, $attachment);

        abort_unless($attachment->thumbnail_path !== null, 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->thumbnail_path), 404);

        return $disk->response($attachment->thumbnail_path);
    }
}
