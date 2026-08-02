<?php

namespace App\Enums;

/**
 * Whether an export carries the files attached to an item.
 *
 * Separate from {@see ExportImageMode} because the two are different problems:
 * an inline image is already part of the text and only needs a destination,
 * while an attachment is not mentioned in the content at all — carrying it means
 * writing it into the archive *and* giving the document a place to link to it.
 */
enum ExportAttachmentMode: string
{
    /**
     * The files stay behind; the export is the content alone.
     */
    case None = 'none';

    /**
     * Every attachment is written into its own directory in the archive and
     * listed under the item it belongs to — which is why choosing it turns the
     * export into a ZIP.
     */
    case Files = 'files';

    /**
     * The human-readable, translatable label for the mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => __('Leave the files behind'),
            self::Files => __('Include the files in the archive'),
        };
    }
}
