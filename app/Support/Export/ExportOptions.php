<?php

namespace App\Support\Export;

use App\Enums\ExportAttachmentMode;
use App\Enums\ExportFileLayout;
use App\Enums\ExportFormat;
use App\Enums\ExportImageMode;

/**
 * What an export was asked to include.
 *
 * The one value object the export feature shares: the modal binds to it, the
 * audit event records it, and a later per-user preference will persist it. The
 * renderers stay concrete (see docs/adr/0002-export-has-no-format-abstraction.md),
 * so this — not a format interface — is the seam that grows as options arrive.
 */
final readonly class ExportOptions
{
    /**
     * Where a user's export habits are remembered, as a preference key. Global
     * rather than per project: how someone likes their exports shaped is a habit,
     * not a property of the board they happen to be on.
     */
    public const string PREFERENCE_KEY = 'export.options';

    /**
     * @param  bool  $metadata  include the YAML front-matter block
     * @param  bool  $descendants  include the exported item's whole subtree
     * @param  int|null  $depth  how many levels of descendants to include, or null
     *                           for every level — "all" is stored as an absence
     *                           rather than a number, so a subtree that grows
     *                           deeper later still exports in full
     * @param  bool  $canceled  include canceled tasks, which are otherwise skipped
     *                          along with everything below them
     * @param  bool  $archived  include archived tasks, which are otherwise skipped
     *                          along with everything below them
     * @param  bool  $drafts  include draft docs found among the descendants; a
     *                        directly-exported draft always exports
     * @param  bool  $comments  include the discussion under each exported item
     * @param  bool  $bundle  write one file per item and deliver them as an
     *                        archive, instead of one concatenated document
     * @param  ExportFileLayout  $layout  how that archive arranges its files
     * @param  bool  $datePrefix  prepend the date to the download filename
     * @param  ExportFormat  $format  what the export is written as
     * @param  list<string>|null  $only  the exact items to include, keyed
     *                                   "task:12" — null means "whatever depth
     *                                   and the filters say", which is the quick
     *                                   path the depth select drives
     * @param  ExportAttachmentMode  $attachments  whether the attached files travel
     * @param  ExportImageMode  $images  how the images inside the content leave
     *                                   the app: by URL, as links, or embedded
     */
    public function __construct(
        public bool $metadata = true,
        public bool $descendants = false,
        public ?int $depth = null,
        public bool $canceled = false,
        public bool $archived = false,
        public bool $drafts = false,
        public bool $comments = false,
        public bool $bundle = false,
        public ExportFileLayout $layout = ExportFileLayout::Flat,
        public bool $datePrefix = false,
        public ExportFormat $format = ExportFormat::Markdown,
        public ?array $only = null,
        public ExportAttachmentMode $attachments = ExportAttachmentMode::None,
        public ExportImageMode $images = ExportImageMode::Embed,
    ) {}

    /**
     * Whether this export has to be delivered as an archive: because it is one
     * file per item, or because the images travel as files and need somewhere to
     * live. Either way there is nothing to put on a clipboard.
     */
    public function needsArchive(): bool
    {
        return $this->bundle
            || $this->images === ExportImageMode::Files
            || $this->attachments === ExportAttachmentMode::Files;
    }

    /**
     * The same options, but for one item on its own — what each file of a bundle
     * is rendered with, since a bundle expresses the tree through its files
     * rather than by concatenating it into one document.
     */
    public function forSingleItem(): self
    {
        return new self(
            metadata: $this->metadata,
            descendants: false,
            depth: $this->depth,
            canceled: $this->canceled,
            archived: $this->archived,
            drafts: $this->drafts,
            comments: $this->comments,
            bundle: false,
            layout: $this->layout,
            datePrefix: $this->datePrefix,
            format: $this->format,
            only: $this->only,
            attachments: $this->attachments,
            images: $this->images,
        );
    }

    /**
     * The options as recorded in the audit event's metadata.
     *
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return [
            'metadata' => $this->metadata,
            'descendants' => $this->descendants,
            'depth' => $this->depth === null ? 'all' : (string) $this->depth,
            'canceled' => $this->canceled,
            'archived' => $this->archived,
            'drafts' => $this->drafts,
            'comments' => $this->comments,
            'bundle' => $this->bundle,
            'layout' => $this->layout->value,
            'date_prefix' => $this->datePrefix,
            'format' => $this->format->value,
            'selection' => $this->only === null ? 'all' : (string) count($this->only),
            'attachments' => $this->attachments->value,
            'images' => $this->images->value,
        ];
    }
}
