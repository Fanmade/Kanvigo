<?php

namespace App\Http\Controllers\Concerns;

use App\Audit\AccessAudit;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Facades\Audit;
use App\Support\Images\ImageTransformer;
use App\Support\Images\RasterImageTypes;
use App\Support\Images\TransformSpec;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Shared handling of the `width` / `height` / `format` / `quality` query
 * parameters accepted by every surface that serves image bytes.
 *
 * Absent parameters mean "give me the file", so the caller streams the stored
 * bytes untouched. Any parameter is an explicit opt-in to a re-encode.
 */
trait ResolvesImageTransforms
{
    /**
     * The rendition the request asked for, or null when it asked for none.
     */
    protected function imageTransformSpec(Request $request): ?TransformSpec
    {
        $validated = $request->validate([
            'width' => ['sometimes', 'integer', 'min:1', 'max:'.TransformSpec::MAX_DIMENSION],
            'height' => ['sometimes', 'integer', 'min:1', 'max:'.TransformSpec::MAX_DIMENSION],
            'format' => ['sometimes', 'string', Rule::in(TransformSpec::FORMATS)],
            'quality' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validated === []) {
            return null;
        }

        $format = $validated['format'] ?? 'webp';

        if (! app(ImageTransformer::class)->supportsFormat($format)) {
            throw ValidationException::withMessages([
                'format' => 'The "'.$format.'" format cannot be encoded on this server.',
            ]);
        }

        return new TransformSpec(
            width: $validated['width'] ?? null,
            height: $validated['height'] ?? null,
            format: $format,
            quality: $validated['quality'] ?? TransformSpec::DEFAULT_QUALITY,
        );
    }

    /**
     * The transformed image as a download response, audited like any other
     * attachment read. A file the active driver cannot decode is a 422 rather
     * than a silent byte-exact fallback — the caller asked for a rendition and
     * has to know it did not get one.
     */
    protected function transformedAttachmentResponse(Attachment $attachment, TransformSpec $spec, ?User $actor = null): Response
    {
        // A decoder rasterizes far more than "images" — Imagick decodes PDF, EPS,
        // PostScript and SVG too, through delegates that shell out — so
        // "transform() returned something" is neither a safe proxy for "this is an
        // image" nor a surface to expose. Gate on the stored MIME type first: the
        // spec mandates a 422 for a non-image attachment, not a 200 with page 1 of
        // a PDF re-encoded as WebP ({@see RasterImageTypes}).
        if (! RasterImageTypes::isDecodable($attachment->mime_type)) {
            throw ValidationException::withMessages([
                'image' => 'This attachment is not an image that can be transformed.',
            ]);
        }

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $rendered = app(ImageTransformer::class)->transform((string) $disk->get($attachment->path), $spec);

        if ($rendered === null) {
            // Keyed on 'image' rather than any of the four transform params —
            // the caller may not have sent 'width' at all (e.g. only 'format').
            throw ValidationException::withMessages([
                'image' => 'This attachment is not an image that can be transformed.',
            ]);
        }

        $event = AccessAudit::attachmentDownloaded($attachment);
        Audit::record($actor === null ? $event : $event->withActor($actor->getKey()));

        $name = pathinfo($attachment->name, PATHINFO_FILENAME).'.'.$spec->extension();

        return response($rendered, 200, [
            'Content-Type' => $spec->mimeType(),
            'Content-Length' => (string) strlen($rendered),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name),
        ]);
    }
}
