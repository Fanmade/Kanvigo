<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesImageTransforms;
use App\Http\Controllers\Concerns\ServesScopedAttachments;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an attachment's raw bytes over a short-lived signed link, so a client
 * that cannot present a session cookie or a bearer token — an MCP agent piping
 * the file to disk, for instance — can still fetch it.
 *
 * The link is not a bypass: the signature names the user it was issued for, and
 * that user's access to the attachment is re-checked here, when the link is
 * followed. A membership revoked after the link was minted takes effect
 * immediately, and the download is audited against the named user like any
 * other download.
 */
class SignedAttachmentDownloadController extends Controller
{
    use ResolvesImageTransforms;
    use ServesScopedAttachments;

    /**
     * Stream the attachment as a download to the user the link was issued for.
     */
    public function __invoke(Request $request, Attachment $attachment, User $user): StreamedResponse|Response
    {
        abort_if(Gate::forUser($user)->denies('view', $attachment), 404);

        $spec = $this->imageTransformSpec($request);

        if ($spec === null) {
            return $this->downloadAttachment($attachment, $user);
        }

        return $this->transformedAttachmentResponse($attachment, $spec, $user);
    }
}
