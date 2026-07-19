<?php

namespace App\Mcp\Tools;

use App\Audit\AccessAudit;
use App\Models\Attachment;
use App\Support\Facades\Audit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Gets the content of an attachment by its id, including inline images embedded in a project or task description. Image and audio attachments are returned as viewable content, and text-based attachments (logs, JSON, XML, CSV, …) are returned as text — up to 256 KiB per call, with an optional "offset" to page through larger files. Other file types return their metadata with a download link. Attachment ids are listed by the get-project and get-task tools. Only attachments in projects the authenticated user is a member of are accessible.')]
#[IsReadOnly]
class GetAttachmentTool extends Tool
{
    /**
     * The largest amount of decoded text returned inline. Larger files are
     * truncated to this many bytes (at a UTF-8 character boundary) with a notice
     * pointing at the download link, so a big log can't blow up the response.
     */
    private const int MAX_INLINE_BYTES = 256 * 1024;

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
        ], [
            'id.required' => 'You must provide the attachment id. Attachment ids are listed by the get-project and get-task tools.',
        ]);

        $attachment = Attachment::query()->whereKey($validated['id'])->first();

        if ($attachment === null || ! $request->user()->can('view', $attachment)) {
            return Response::error('No attachment with id "'.$validated['id'].'" exists, or you do not have access to it.');
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return Response::error('The attachment file is no longer available.');
        }

        $mimeType = (string) $attachment->mime_type;
        $contents = (string) $disk->get($attachment->path);

        $isImage = str_starts_with($mimeType, 'image/');
        $isAudio = str_starts_with($mimeType, 'audio/');
        // Inline text-based files (logs, JSON, …) so an agent can read them — but
        // only if the bytes are actually valid UTF-8, so a binary file mislabelled
        // as text falls through to the metadata link rather than emitting garbage.
        $isInlineText = $this->isTextual($mimeType) && mb_check_encoding($contents, 'UTF-8');

        // Serving the bytes (image, audio or inline text) is a content read of the
        // attachment over MCP — audit it like a REST/web download. The metadata-only
        // fallthrough below discloses no content, so it is not audited.
        if ($isImage || $isAudio || $isInlineText) {
            Audit::record(AccessAudit::attachmentDownloaded($attachment));
        }

        if ($isImage) {
            return Response::image($contents, $mimeType);
        }

        if ($isAudio) {
            return Response::audio($contents, $mimeType);
        }

        if ($isInlineText) {
            return Response::text($this->inlineText($attachment, $contents, $validated['offset'] ?? 0));
        }

        return Response::text('Attachment "'.$attachment->name.'" ('.($mimeType !== '' ? $mimeType : 'unknown type').', '.$attachment->size.' bytes) cannot be displayed inline. Only image, audio and text-based attachments are viewable; download it from '.$attachment->downloadUrl().' instead.');
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
        ];
    }
}
