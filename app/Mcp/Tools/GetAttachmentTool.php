<?php

namespace App\Mcp\Tools;

use App\Audit\AccessAudit;
use App\Mcp\Concerns\ResolvesAuthenticatedUser;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Facades\Audit;
use App\Support\Images\ImageTransformer;
use App\Support\Images\RasterImageTypes;
use App\Support\Images\TransformSpec;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets the content of an attachment by its id, including inline images embedded in a project or task description. Image and audio attachments are returned as viewable content, and text-based attachments (logs, JSON, XML, CSV, …) are returned as text — up to 256 KiB per call, with an optional "offset" to page through larger files. Large images are returned downscaled to a vision-sized rendition by default so the response stays small; pass "width" and/or "height" (the image is fitted inside that box, aspect ratio preserved, never enlarged) plus optional "format" (webp, jpeg, png, avif) and "quality" to choose a rendition yourself. The get-task and get-project tools report each attachment size and pixel dimensions, so check those before asking for a smaller version of an already-small image. Other file types return their metadata only. Every response also carries a short-lived signed URL the raw file can be downloaded from with a plain HTTP request (no credentials), so an agent can write the original bytes to disk — the transform parameters work on that URL too. Attachment ids are listed by the get-project and get-task tools. Only attachments in projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class GetAttachmentTool extends Tool
{
    use ResolvesAuthenticatedUser;

    /**
     * The largest amount of decoded text returned inline. Larger files are
     * truncated to this many bytes (at a UTF-8 character boundary) with a notice
     * pointing at the download link, so a big log can't blow up the response.
     */
    private const int MAX_INLINE_BYTES = 256 * 1024;

    /**
     * Above either of these an image is downscaled before it is inlined. Byte size
     * catches a heavily-detailed small image; the edge length catches a long, thin
     * scan that is modest on one axis and enormous on the other.
     */
    private const int AUTO_TRANSFORM_BYTES = 512 * 1024;

    /**
     * The largest encoded image returned inline. Anything still above this after a
     * transform is handed over as a link instead — an oversized base64 payload is
     * what breaks the client in the first place.
     */
    private const int MAX_INLINE_IMAGE_BYTES = 2 * 1024 * 1024;

    /**
     * Audio cannot be transformed, so it is simply gated: past this it becomes a
     * link. Same defect as oversized images, same remedy.
     */
    private const int MAX_INLINE_AUDIO_BYTES = 4 * 1024 * 1024;

    /**
     * Textual `application/*` MIME types returned inline as text. `text/*` is
     * always treated as textual and isn't listed here.
     *
     * @var list<string>
     */
    private const array TEXTUAL_MIME_TYPES = [
        'application/json',
        'application/ld+json',
        'application/xml',
        'application/x-ndjson',
        'application/yaml',
        'application/x-yaml',
        'application/csv',
        'application/javascript',
        'application/x-sh',
    ];

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'width' => ['sometimes', 'integer', 'min:1', 'max:'.TransformSpec::MAX_DIMENSION],
            'height' => ['sometimes', 'integer', 'min:1', 'max:'.TransformSpec::MAX_DIMENSION],
            'format' => ['sometimes', 'string', 'in:'.implode(',', TransformSpec::FORMATS)],
            'quality' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ], [
            'id.required' => 'You must provide the attachment id. Attachment ids are listed by the get-project and get-task tools.',
        ]);

        $user = $this->authenticatedUser($request);
        $attachment = Attachment::query()->whereKey($validated['id'])->first();

        if ($attachment === null || ! $user->can('view', $attachment)) {
            return Response::error('No attachment with id "'.$validated['id'].'" exists, or you do not have access to it.');
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return Response::error('The attachment file is no longer available.');
        }

        $mimeType = (string) $attachment->mime_type;
        $contents = (string) $disk->get($attachment->path);

        // Only the raster formats a driver may decode count as an image here: an
        // SVG must not be handed to a delegate-backed coder, and its bytes are of
        // no use to a vision model either — it falls through to the inline-text or
        // metadata branches below like any other file ({@see RasterImageTypes}).
        $isImage = RasterImageTypes::isDecodable($mimeType);
        $isAudio = str_starts_with($mimeType, 'audio/');

        // The four transform params only mean something for an image. Silently
        // ignoring them on, say, an audio/mpeg attachment would let a caller
        // believe a resize happened when nothing was honored — match the REST
        // download's 422 for the same request shape.
        if (! $isImage && $this->requestedSpec($validated) !== null) {
            return Response::error('The "width", "height", "format" and "quality" parameters only apply to image attachments; this attachment is '.($mimeType !== '' ? $mimeType : 'of unknown type').'.');
        }
        // Inline text-based files (logs, JSON, …) so an agent can read them — but
        // only if the bytes are actually valid UTF-8, so a binary file mislabelled
        // as text falls through to the metadata link rather than emitting garbage.
        $isInlineText = $this->isTextual($mimeType) && mb_check_encoding($contents, 'UTF-8');

        $downloadLink = Response::text($this->downloadLink($attachment, $user));

        // Serving the bytes (image, audio or inline text) is a content read of the
        // attachment over MCP — audit it like a REST/web download, at the point the
        // bytes are actually inlined. Any fallthrough to metadata-plus-link below
        // discloses no content, so it is not audited; the signed link every response
        // carries audits itself when it is actually followed.

        if ($isImage) {
            return $this->imageResponse($attachment, $contents, $mimeType, $validated, $downloadLink);
        }

        if ($isAudio) {
            if (strlen($contents) > self::MAX_INLINE_AUDIO_BYTES) {
                return Response::make([
                    Response::text('Attachment "'.$attachment->name.'" ('.$attachment->size.' bytes) is too large to return inline. Fetch it from the signed URL below.'),
                    $downloadLink,
                ]);
            }

            Audit::record(AccessAudit::attachmentDownloaded($attachment));

            return Response::make([Response::audio($contents, $mimeType), $downloadLink]);
        }

        if ($isInlineText) {
            Audit::record(AccessAudit::attachmentDownloaded($attachment));

            return Response::make([
                Response::text($this->inlineText($attachment, $contents, $validated['offset'] ?? 0)),
                $downloadLink,
            ]);
        }

        return Response::make([
            Response::text('Attachment "'.$attachment->name.'" ('.($mimeType !== '' ? $mimeType : 'unknown type').', '.$attachment->size.' bytes) cannot be displayed inline. Only image, audio and text-based attachments are viewable.'),
            $downloadLink,
        ]);
    }

    /**
     * The response for an image attachment.
     *
     * A caller that named a rendition gets exactly that. A caller that named
     * nothing gets the stored bytes when they are small enough to inline safely,
     * and a vision-sized WebP when they are not — an untransformed multi-megabyte
     * image is what breaks the client, and a model downsamples past 1568 px on its
     * side regardless, so the full-resolution bytes buy nothing.
     *
     * @param  array<string, mixed>  $validated
     */
    private function imageResponse(Attachment $attachment, string $contents, string $mimeType, array $validated, Response $downloadLink): Response|ResponseFactory
    {
        $transformer = app(ImageTransformer::class);
        $requested = $this->requestedSpec($validated);

        // A format the caller explicitly named but this driver cannot encode is
        // an error, not a silent fallback: without this check transform()
        // returns null, transformFailed sets, and the *original* untransformed
        // bytes get inlined below with no notice — the caller believes it got
        // the format it asked for.
        if ($requested !== null && ! $transformer->supportsFormat($requested->format)) {
            return Response::error('The "'.$requested->format.'" format cannot be encoded on this server.');
        }

        $dimensions = $transformer->dimensions($contents);
        $notice = null;
        $transformFailed = false;

        $spec = $requested;

        if ($spec === null) {
            $tooLarge = strlen($contents) > self::AUTO_TRANSFORM_BYTES
                || ($dimensions !== null && max($dimensions) > TransformSpec::DEFAULT_MAX_EDGE);

            $spec = $tooLarge ? TransformSpec::visionDefault() : null;
        }

        if ($spec !== null) {
            $rendered = $transformer->transform($contents, $spec);

            if ($rendered === null) {
                $transformFailed = true;
                // The caller is about to receive the original, untouched bytes
                // instead of the rendition it asked for (or the auto-downscale it
                // implicitly opted into) — it must never be told nothing.
                $notice = 'This image could not be transformed on this server; the original, untransformed bytes are returned instead.';
            } else {
                // A bounds-less spec (format/quality only) never resizes, and even
                // a bounded spec doesn't when the box was already larger than the
                // source (targetFor()'s upscale guard returns the source size
                // unchanged) — say "re-encoded", not "downscaled to W×H", or the
                // notice claims a resize that did not happen.
                if ($dimensions === null) {
                    $target = null;
                    $resized = false;
                } else {
                    $target = $spec->targetFor($dimensions[0], $dimensions[1]);
                    $resized = $spec->boundsGiven() && $target !== $dimensions;
                }

                $notice = ($resized
                        ? 'This image was downscaled to '.$target[0].'×'.$target[1].' and re-encoded as '.$spec->mimeType()
                        : 'This image was re-encoded as '.$spec->mimeType())
                    .'. The original is '.($dimensions === null ? 'of unknown size' : $dimensions[0].'×'.$dimensions[1])
                    .' and '.$attachment->size.' bytes — fetch the signed URL below for it untouched.';
                $contents = $rendered;
                $mimeType = $spec->mimeType();
            }
        }

        if (strlen($contents) > self::MAX_INLINE_IMAGE_BYTES) {
            $reason = $transformFailed
                ? 'it is too large, and this server could not re-encode it to a smaller rendition'
                : 'even the re-encoded rendition is still too large to inline';

            return Response::make([
                Response::text('Attachment "'.$attachment->name.'" ('.$mimeType.', '.strlen($contents).' bytes) cannot be displayed inline: '.$reason.'. Fetch it from the signed URL below.'),
                $downloadLink,
            ]);
        }

        Audit::record(AccessAudit::attachmentDownloaded($attachment));

        $blocks = [Response::image($contents, $mimeType)];

        if ($notice !== null) {
            $blocks[] = Response::text($notice);
        }

        $blocks[] = $downloadLink;

        return Response::make($blocks);
    }

    /**
     * The rendition the caller asked for, or null when they asked for none.
     *
     * @param  array<string, mixed>  $validated
     */
    private function requestedSpec(array $validated): ?TransformSpec
    {
        $keys = ['width', 'height', 'format', 'quality'];

        if (array_intersect_key($validated, array_flip($keys)) === []) {
            return null;
        }

        return new TransformSpec(
            width: $validated['width'] ?? null,
            height: $validated['height'] ?? null,
            format: $validated['format'] ?? 'webp',
            quality: $validated['quality'] ?? TransformSpec::DEFAULT_QUALITY,
        );
    }

    /**
     * The block naming the signed URL the raw file can be fetched from. It is
     * returned alongside every accessible attachment — viewable or not — because
     * seeing a photo is not the same as having the file: writing it to disk,
     * processing it or passing it to another tool all need the bytes themselves.
     *
     * The link authorizes itself for the calling user and expires, so it works
     * with a plain HTTP client that holds no session cookie.
     */
    private function downloadLink(Attachment $attachment, User $user): string
    {
        $minutes = (int) config('attachments.signed_url_ttl');

        return 'Download the raw file ("'.$attachment->name.'", '.$attachment->size.' bytes) from this signed URL, '
            .'which needs no credentials and expires in '.$minutes.' minutes: '.$attachment->signedDownloadUrl($user);
    }

    /**
     * Whether an attachment's MIME type is text-based and should be returned
     * inline as text. All `text/*` types qualify, plus a small allow-list of
     * textual `application/*` types.
     */
    private function isTextual(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/')
            || in_array($mimeType, self::TEXTUAL_MIME_TYPES, true);
    }

    /**
     * One page of an attachment's contents for inline display: up to
     * {@see MAX_INLINE_BYTES} starting at the requested byte offset, cut at UTF-8
     * character boundaries. When the file spans more than one page, a header
     * states the byte range and the offset to pass to read the next part — so an
     * agent can paginate to whichever section it needs.
     */
    private function inlineText(Attachment $attachment, string $contents, int $offset): string
    {
        $totalBytes = strlen($contents);

        if ($offset >= $totalBytes && $totalBytes > 0) {
            return 'Offset '.$offset.' is past the end of the file, which is '.$totalBytes.' bytes.';
        }

        $window = mb_strcut($contents, $offset, self::MAX_INLINE_BYTES, 'UTF-8');
        $end = $offset + strlen($window);

        // A whole small file read from the start needs no pagination header.
        if ($offset === 0 && $end >= $totalBytes) {
            return $window;
        }

        $header = $end < $totalBytes
            ? '[bytes '.$offset.'–'.$end.' of '.$totalBytes.' — more available: call again with offset='.$end.']'
            : '[bytes '.$offset.'–'.$end.' of '.$totalBytes.' — end of file]';

        return $header."\n\n".$window;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The attachment id, as listed by the get-project and get-task tools.')
                ->required(),
            'offset' => $schema->integer()
                ->description('For text-based attachments, the byte offset to start reading from (default 0). Up to 256 KiB is returned per call; when more remains, the response states the offset to pass to read the next part. Ignored for images and audio.'),
            'width' => $schema->integer()->description('For images, the maximum width in pixels of the returned rendition (1–4096). The image is fitted inside the width/height box with its aspect ratio preserved and is never enlarged.'),
            'height' => $schema->integer()->description('For images, the maximum height in pixels of the returned rendition (1–4096). Give this for tall images — a page scan can be narrow and still enormous.'),
            'format' => $schema->string()->description('For images, the encoding of the returned rendition: webp (default), jpeg, png or avif.'),
            'quality' => $schema->integer()->description('For images, the encoder quality from 1 to 100 (default 80). Ignored for png.'),
        ];
    }
}
