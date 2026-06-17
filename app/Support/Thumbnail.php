<?php

namespace App\Support;

use GdImage;
use Illuminate\Support\Facades\Process;
use Imagick;
use Throwable;

class Thumbnail
{
    /**
     * The maximum width or height, in pixels, of a generated thumbnail.
     */
    public const int MAX_DIMENSION = 256;

    /**
     * Generate a small PNG thumbnail for an uploaded file, picking the right
     * strategy from its MIME type.
     *
     * Returns null when no thumbnail can be created, allowing callers to fall
     * back to a generic icon.
     */
    public static function generate(string $contents, ?string $mimeType = null): ?string
    {
        if ($mimeType === 'application/pdf') {
            $raster = self::rasterizePdf($contents);

            return $raster === null ? null : self::fromImage($raster);
        }

        return self::fromImage($contents);
    }

    /**
     * Generate a small PNG thumbnail from raw image bytes.
     *
     * Returns null when the bytes are not a decodable raster image, allowing
     * callers to fall back to a generic icon for unsupported file types.
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

        $scale = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height, 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($thumbnail);
        $data = (string) ob_get_clean();

        return $data === '' ? null : $data;
    }

    /**
     * Render the first page of a PDF to PNG bytes with Ghostscript, onto a white
     * background. The page is fit into a box matched to its aspect ratio and
     * capped near thumbnail size, so a poster-sized page renders just as cheaply
     * as a letter-sized one instead of blowing up to tens of megapixels and
     * pegging the CPU. Returns null when Ghostscript is unavailable or the
     * document cannot be rendered.
     */
    private static function rasterizePdf(string $contents): ?string
    {
        if (! str_starts_with($contents, '%PDF')) {
            return null;
        }

        $page = self::pdfPageSizePoints($contents);

        if ($page === null) {
            return null;
        }

        // Fit the page into a box no larger than twice the thumbnail size, so the
        // downscale stays crisp while the raster stays tiny.
        [$width, $height] = $page;
        $box = self::MAX_DIMENSION * 2;
        $longest = max($width, $height);
        $boxWidth = max(1, (int) round($box * $width / $longest));
        $boxHeight = max(1, (int) round($box * $height / $longest));

        $input = tempnam(sys_get_temp_dir(), 'pdfin');
        $output = tempnam(sys_get_temp_dir(), 'pdfout');

        try {
            file_put_contents($input, $contents);

            $result = Process::timeout(20)->run([
                (string) config('attachments.ghostscript'),
                '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
                '-sDEVICE=png16m',
                '-dFirstPage=1', '-dLastPage=1',
                '-dPDFFitPage', '-g'.$boxWidth.'x'.$boxHeight,
                '-o', $output,
                $input,
            ]);

            if (! $result->successful()) {
                return null;
            }

            $png = (string) file_get_contents($output);

            return $png === '' ? null : $png;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    /**
     * Measure a PDF's first page, in points, without rendering it.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function pdfPageSizePoints(string $contents): ?array
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'pdfsize');

        try {
            file_put_contents($temporaryFile, $contents);

            $imagick = new Imagick;
            $imagick->setResolution(72, 72);
            $imagick->pingImage($temporaryFile.'[0]');
            $size = [max(1, $imagick->getImageWidth()), max(1, $imagick->getImageHeight())];
            $imagick->clear();

            return $size;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($temporaryFile);
        }
    }

    /**
     * Rotate JPEGs according to their EXIF orientation so portrait photos taken
     * on phones are not displayed sideways.
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
