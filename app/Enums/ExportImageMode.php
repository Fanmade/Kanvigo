<?php

namespace App\Enums;

/**
 * How the images inside exported content leave the app.
 *
 * The three modes trade portability against size, and none of them is right for
 * every reader — which is why this is a choice rather than a default nobody sees.
 */
enum ExportImageMode: string
{
    /**
     * `![alt](url)` — renders inside Kanvigo for a member of the project, and
     * nowhere else: the attachment routes are authenticated and authorized
     * against the owning project.
     */
    case Embed = 'embed';

    /**
     * `[diagram.png](url)` — never renders inline, and is therefore honest
     * everywhere: a reader without access sees a link they cannot open rather
     * than a broken image.
     */
    case Link = 'link';

    /**
     * A downscaled `data:` URI — the file carries its own images and works with
     * no access to the instance at all, at the cost of its size.
     */
    case Inline = 'inline';

    /**
     * The human-readable, translatable label for the mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::Embed => __('Show images by URL'),
            self::Link => __('List images as links'),
            self::Inline => __('Embed images in the file'),
        };
    }
}
