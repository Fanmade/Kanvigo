<?php

namespace App\Support\Attachments;

use App\Support\Images\RasterImageTypes;

/**
 * Which uploaded types may be rendered inline in the browser, from this
 * application's own origin.
 *
 * An attachment is attacker-controlled content served from a URL that carries
 * the viewer's session cookie. A type the browser renders as a *document* —
 * SVG, HTML, XHTML, XML — can carry script, and that script then runs as us:
 * stored XSS reachable with nothing more than attachment-create rights on one
 * project. So inline display is an allow-list of types that either cannot script
 * (raster images, plain text, audio, video) or are rendered in the browser's own
 * sandboxed viewer (PDF); everything else is handed over as a download, which no
 * browser executes.
 *
 * SVG is the notable exclusion. It is a picture to a person and a document to a
 * parser, and there is no way to serve it inline that keeps only the first
 * meaning — the same reason it never reaches an image decoder here
 * ({@see RasterImageTypes}).
 */
final class InlineSafeTypes
{
    /**
     * Non-image types safe to render inline. Prefixed entries ("audio/") match a
     * whole family; the rest are exact.
     *
     * @var list<string>
     */
    private const array ADDITIONAL = [
        'application/pdf',
        'text/plain',
        'audio/',
        'video/',
    ];

    /**
     * Whether bytes labelled with this MIME type may be served with an inline
     * content disposition.
     */
    public static function isSafe(?string $mimeType): bool
    {
        $mimeType = strtolower(trim((string) $mimeType));

        if (RasterImageTypes::isDecodable($mimeType)) {
            return true;
        }

        foreach (self::ADDITIONAL as $type) {
            $matches = str_ends_with($type, '/')
                ? str_starts_with($mimeType, $type)
                : $mimeType === $type;

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
