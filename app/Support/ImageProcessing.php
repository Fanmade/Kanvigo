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

    /**
     * Downscale raster image bytes so the longest edge is at most $maxEdge, and
     * re-encode them as PNG. An image already inside the box keeps its
     * dimensions (it is still re-encoded, so the output is uniform whatever came
     * in). Returns null when the bytes are not a decodable raster image, or
     * when their dimensions are absurd enough to look like a decompression bomb.
     */
    public static function downscale(string $contents, int $maxEdge): ?string
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || ($width * $height) > 40_000_000) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return null;
        }

        $source = self::applyExifOrientation($source, $contents, $info[2]);
        $width = imagesx($source);
        $height = imagesy($source);

        $scale = min($maxEdge / $width, $maxEdge / $height, 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($resized);
        $data = (string) ob_get_clean();

        return $data === '' ? null : $data;
    }
}
