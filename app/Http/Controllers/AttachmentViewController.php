<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesScopedAttachments;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentViewController extends Controller
{
    use ServesScopedAttachments;

    /**
     * Stream an attachment inline (e.g. to display an embedded image in the
     * browser) rather than forcing a download.
     */
    public function __invoke(string $short_name, Attachment $attachment): StreamedResponse
    {
        $this->authorizeScopedAttachment($short_name, $attachment);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->name);
    }
}
