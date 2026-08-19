<?php

namespace App\Support\Images;

/**
 * The MIME types this application is willing to hand to an image decoder.
 *
 * "image/*" is not that set. ImageMagick's decoders for SVG, EPS, PostScript,
 * MVG and MSL are delegate-backed — they shell out to librsvg or Ghostscript, or
 * interpret a scripting language — and several of them have been the vehicle for
 * remote-code-execution and file-disclosure bugs (ImageTragick and its
 * descendants). A stock ImageMagick policy leaves those coders enabled, so the
 * application must not rely on the host being hardened: an "image/svg+xml"
 * attachment passes any `str_starts_with('image/')` gate and reaches librsvg.
 *
 * So the gate is an allow-list of raster formats we actually resize and
 * re-encode, and anything outside it — SVG included — is treated as an opaque
 * file: stored and served untouched, never measured, never transformed. It is
 * still worth hardening the host policy as well (see the administrator manual);
 * this list is the layer that does not depend on the deployment.
 */
final class RasterImageTypes
{
    /**
     * Every raster type the GD and Imagick drivers between them decode. Legacy
     * aliases are listed alongside the canonical types because a browser or an
     * API client picks the label, not us.
     *
     * @var list<string>
     */
    public const array ALLOWED = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/apng',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/heic',
        'image/heif',
        'image/tiff',
        'image/bmp',
        'image/x-ms-bmp',
        'image/x-windows-bmp',
    ];

    /**
     * Whether bytes labelled with this MIME type may be handed to a decoder —
     * to be measured, transformed, or inlined as image content.
     */
    public static function isDecodable(?string $mimeType): bool
    {
        return in_array(strtolower(trim((string) $mimeType)), self::ALLOWED, true);
    }
}
