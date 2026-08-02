<?php

namespace App\Support\Export;

/**
 * What one document needs to know about the export it belongs to.
 *
 * A single item rendered on its own needs none of this. A document inside an
 * archive does: where the other items' files are, which image and attachment
 * decisions are being shared across the whole archive, and how to get back up
 * and down the tree from here. Passing them as one value keeps the renderers'
 * signatures honest as the archive grows more to say.
 */
final readonly class ExportContext
{
    /**
     * @param  array<string, string>  $localLinks  in-archive link targets, keyed
     *                                             "task:12" as the reference
     *                                             markup carries them
     */
    public function __construct(
        public array $localLinks = [],
        public ?ExportImages $images = null,
        public ?ExportAttachments $attachments = null,
        public ?ExportNavigation $navigation = null,
    ) {}
}
