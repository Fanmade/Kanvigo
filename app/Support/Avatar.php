<?php

namespace App\Support;

use GdImage;

class Avatar
{
    /**
     * The width and height, in pixels, of a stored avatar image.
     */
    public const int SIZE = 256;

    /**
     * Crop raw image bytes to a centred square and resize them to a fixed
     * avatar size, returning PNG bytes.
     *
     * Returns null when the bytes are not a decodable raster image, allowing
     * callers to reject the upload.
     */
    public static function fromImage(string $contents): ?string
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;

        // Guard against absurd dimensions (e.g. decompression bombs).
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

        // Centre-crop the longest edge down to a square before resizing.
        $edge = min($width, $height);
        $sourceX = (int) (($width - $edge) / 2);
        $sourceY = (int) (($height - $edge) / 2);

        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagecopyresampled($square, $source, 0, 0, $sourceX, $sourceY, self::SIZE, self::SIZE, $edge, $edge);

        ob_start();
        imagepng($square);
        $data = (string) ob_get_clean();

        return $data === '' ? null : $data;
    }

    /**
     * Rotate JPEGs according to their EXIF orientation so portrait photos taken
     * on phones are not cropped sideways.
     */
    private static function applyExifOrientation(GdImage $image, string $contents, int $type): GdImage
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
