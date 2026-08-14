<?php

namespace App\Support\Images;

use App\Support\Images\Contracts\ImageDriver;
use App\Support\Images\Drivers\GdDriver;
use App\Support\Images\Drivers\ImagickDriver;

/**
 * The single entry point for image renditions. Every surface that serves image
 * bytes — the REST download, the signed link, the MCP tool — calls this rather
 * than an image library directly, so format support and the decompression-bomb
 * guard are decided in one place.
 */
class ImageTransformer
{
    /**
     * Source images above this pixel count are refused outright. A file can be
     * small on disk and enormous once decoded, so the guard is on dimensions
     * rather than byte size.
     */
    public const int BOMB_PIXELS = 40_000_000;

    private ?ImageDriver $resolved = null;

    public function __construct(?ImageDriver $driver = null)
    {
        $this->resolved = $driver;
    }

    /**
     * The active driver: Imagick when its extension is loaded, GD otherwise.
     */
    public function driver(): ImageDriver
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $imagick = new ImagickDriver;

        return $this->resolved = $imagick->available() ? $imagick : new GdDriver;
    }

    /**
     * Whether the active driver can encode the given format.
     */
    public function supportsFormat(string $format): bool
    {
        return in_array($format, TransformSpec::FORMATS, true) && $this->driver()->supportsFormat($format);
    }

    /**
     * The pixel dimensions of the given bytes, or null when they are not a
     * decodable raster image.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $bytes): ?array
    {
        return $this->driver()->dimensions($bytes);
    }

    /**
     * The image re-rendered to the given spec, or null when it cannot be.
     */
    public function transform(string $bytes, TransformSpec $spec): ?string
    {
        return $this->driver()->transform($bytes, $spec);
    }
}
