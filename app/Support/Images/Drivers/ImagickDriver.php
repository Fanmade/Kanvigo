<?php

namespace App\Support\Images\Drivers;

use App\Support\Images\Contracts\ImageDriver;
use App\Support\Images\ImageTransformer;
use App\Support\Images\TransformSpec;
use Imagick;
use Throwable;

/**
 * Imagick-backed image transforms. Preferred over GD because it decodes the
 * formats scanned material actually arrives in — HEIC and TIFF — which GD
 * cannot read at all.
 */
class ImagickDriver implements ImageDriver
{
    public function available(): bool
    {
        return extension_loaded('imagick');
    }

    public function supportsFormat(string $format): bool
    {
        // Imagick's own format table is far broader than the four formats this
        // app curates (it happily reports TIFF, HEIC, PDF...); gate on our list
        // first so an out-of-scope format is rejected even though Imagick could
        // technically encode it.
        if (! in_array($format, TransformSpec::FORMATS, true)) {
            return false;
        }

        if (! $this->available()) {
            return false;
        }

        return Imagick::queryFormats(strtoupper($format)) !== [];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $bytes): ?array
    {
        try {
            $image = new Imagick;
            $image->readImageBlob($bytes);
            $geometry = $image->getImageGeometry();
            $image->clear();
        } catch (Throwable) {
            return null;
        }

        if ($geometry['width'] < 1 || $geometry['height'] < 1) {
            return null;
        }

        return [$geometry['width'], $geometry['height']];
    }

    public function transform(string $bytes, TransformSpec $spec): ?string
    {
        if (! $this->supportsFormat($spec->format)) {
            return null;
        }

        try {
            $image = new Imagick;
            $image->readImageBlob($bytes);

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if ($width < 1 || $height < 1 || ($width * $height) > ImageTransformer::BOMB_PIXELS) {
                $image->clear();

                return null;
            }

            // Flatten a multi-page source (a PDF-ish TIFF, an animated image) to
            // its first frame — the caller asked for one image, not a sequence.
            $image = $image->coalesceImages();
            $image->setFirstIterator();

            $image->autoOrient();
            [$targetWidth, $targetHeight] = $spec->targetFor($width, $height);
            $image->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1);

            $image->setImageFormat($spec->format);
            $image->setImageCompressionQuality($spec->quality);
            $encoded = $image->getImageBlob();
            $image->clear();
        } catch (Throwable) {
            return null;
        }

        return $encoded === '' ? null : $encoded;
    }
}
