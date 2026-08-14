<?php

namespace App\Support\Images\Drivers;

use App\Support\ImageProcessing;
use App\Support\Images\Contracts\ImageDriver;
use App\Support\Images\ImageTransformer;
use App\Support\Images\TransformSpec;

/**
 * GD-backed image transforms. Always available on this application's hosts, and
 * the fallback when Imagick is not installed. GD cannot decode HEIC or TIFF —
 * those files return null here and reach the caller's metadata fallback.
 */
class GdDriver implements ImageDriver
{
    public function available(): bool
    {
        return extension_loaded('gd');
    }

    public function supportsFormat(string $format): bool
    {
        return match ($format) {
            'webp' => function_exists('imagewebp'),
            'jpeg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            'avif' => function_exists('imageavif'),
            default => false,
        };
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $bytes): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1) {
            return null;
        }

        return [$width, $height];
    }

    public function transform(string $bytes, TransformSpec $spec): ?string
    {
        if (! $this->available()) {
            return null;
        }

        if (! $this->supportsFormat($spec->format)) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || ($width * $height) > ImageTransformer::BOMB_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        // A JPEG's stored orientation has to be applied before resizing, or a
        // portrait photo comes back rotated at the new dimensions.
        $source = ImageProcessing::applyExifOrientation($source, $bytes, $info[2]);
        [$targetWidth, $targetHeight] = $spec->targetFor(imagesx($source), imagesy($source));

        // targetFor() already floors each axis at 1; the max() here just proves
        // that invariant to the type checker, which only sees `int`.
        $resized = imagecreatetruecolor(max(1, $targetWidth), max(1, $targetHeight));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($source), imagesy($source));

        ob_start();

        match ($spec->format) {
            'webp' => imagewebp($resized, null, $spec->quality),
            'jpeg' => imagejpeg($resized, null, $spec->quality),
            'avif' => imageavif($resized, null, $spec->quality),
            default => imagepng($resized),
        };

        $encoded = (string) ob_get_clean();

        return $encoded === '' ? null : $encoded;
    }
}
