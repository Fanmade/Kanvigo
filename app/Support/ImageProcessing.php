<?php

namespace App\Support;

use GdImage;

/**
 * Shared GD image-processing helpers used by the avatar and thumbnail pipelines.
 */
class ImageProcessing
{
    /**
     * Rotate a decoded JPEG to its upright orientation per its EXIF Orientation
     * tag. A no-op for non-JPEGs, images without readable EXIF, or already-upright
     * images, and when the rotation itself fails.
     */
    public static function applyExifOrientation(GdImage $image, string $contents, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        $rotation = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($rotation === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $rotation, 0);

        return $rotated === false ? $image : $rotated;
    }
}
