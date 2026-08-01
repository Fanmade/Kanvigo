<?php

namespace App\Support\Export;

use App\Enums\ExportImageMode;
use App\Models\Attachment;
use App\Support\ImageProcessing;
use App\Support\InlineAttachments;
use Illuminate\Support\Facades\Storage;

/**
 * Decides what each inline image in an export turns into, for the one export
 * being rendered.
 *
 * The three modes differ in who can still see the picture afterwards: an
 * embedded URL only renders for a member of the project, a link is honest with
 * everyone, and a Base64 data URI needs no access at all — which is why it is
 * also the only mode with a size problem. Inlining therefore downscales first
 * and spends from a budget; once that runs out the remaining images degrade to
 * links with a note, because a partly-linked export is more useful than a failed
 * one.
 *
 * One instance renders one export: the budget is consumed as it goes, and the
 * attachments it needs are loaded in a single query up front.
 */
class ExportImages
{
    /**
     * The attachments referenced by the export's content, keyed by id. Loaded
     * once for the whole document — a subtree export can repeat the same image
     * across many items.
     *
     * @var array<int, Attachment>
     */
    private array $attachments = [];

    /**
     * How many bytes of encoded image data the export may still spend.
     */
    private int $budget;

    /**
     * The decoded data URIs already produced, keyed by attachment id, so an
     * image used twice is encoded (and paid for) once.
     *
     * @var array<int, string>
     */
    private array $encoded = [];

    public function __construct(public readonly ExportImageMode $mode)
    {
        $this->budget = (int) config('kanvigo.export.inline_budget');
    }

    /**
     * Load the attachments referenced anywhere in the given HTML documents, so
     * rendering never queries per image.
     *
     * @param  list<string>  $documents
     */
    public function prepare(array $documents): void
    {
        if ($this->mode === ExportImageMode::Embed) {
            // Embedding rewrites the URL and needs nothing from the database.
            return;
        }

        $ids = [];

        foreach ($documents as $document) {
            $ids = [...$ids, ...InlineAttachments::referencedIds($document)];
        }

        if ($ids === []) {
            return;
        }

        $this->attachments = Attachment::query()
            ->whereIn('id', array_unique($ids))
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * The Markdown for one inline image: its `src` as stored, the alt text the
     * author gave it, and the absolute URL the embed mode would use.
     */
    public function markdownFor(string $src, string $alt, string $absoluteUrl): string
    {
        $attachment = $this->attachmentFor($src);
        $label = $this->labelFor($attachment, $alt);

        if ($this->mode === ExportImageMode::Link) {
            return '['.$label.']('.$absoluteUrl.')';
        }

        if ($this->mode === ExportImageMode::Inline) {
            $dataUri = $attachment === null ? null : $this->dataUri($attachment);

            if ($dataUri !== null) {
                return '!['.$label.']('.$dataUri.')';
            }

            // Out of budget, or bytes we cannot re-encode (an SVG, a missing
            // file): say so rather than emitting a broken image.
            return '['.$label.']('.$absoluteUrl.') *'.__('image not embedded').'*';
        }

        return '!['.$label.']('.$absoluteUrl.')';
    }

    /**
     * What to call the image: the author's alt text, else the stored file name,
     * else a generic word — a link needs something to say.
     */
    private function labelFor(?Attachment $attachment, string $alt): string
    {
        if ($alt !== '') {
            return $alt;
        }

        return $attachment === null ? __('Image') : (string) $attachment->name;
    }

    /**
     * The attachment an image `src` points at. Inline images embed one of the
     * attachment routes, all of which carry `attachments/{id}/`.
     */
    private function attachmentFor(string $src): ?Attachment
    {
        $ids = InlineAttachments::referencedIds('<img src="'.$src.'">');

        return $ids === [] ? null : ($this->attachments[$ids[0]] ?? null);
    }

    /**
     * The attachment as a downscaled PNG data URI, or null when it cannot be
     * encoded or the export has spent its budget. The same attachment used
     * twice costs the budget once and is encoded once.
     */
    private function dataUri(Attachment $attachment): ?string
    {
        $id = (int) $attachment->getKey();

        if (isset($this->encoded[$id])) {
            return $this->encoded[$id];
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return null;
        }

        $downscaled = ImageProcessing::downscale(
            (string) $disk->get($attachment->path),
            (int) config('kanvigo.export.image_max_edge'),
        );

        if ($downscaled === null) {
            return null;
        }

        $encoded = base64_encode($downscaled);

        if (strlen($encoded) > $this->budget) {
            // Spend nothing and keep the budget for a smaller image later: the
            // one that broke the ceiling is the one that degrades.
            return null;
        }

        $this->budget -= strlen($encoded);

        return $this->encoded[$id] = 'data:image/png;base64,'.$encoded;
    }
}
