<?php

namespace App\Support\Images;

/**
 * An immutable description of a requested image rendition: the box to fit the
 * image inside, the output format, and the encoder quality.
 *
 * Deliberately free of I/O and of any image library, so the part that is easy
 * to get wrong — fitting inside a box without upscaling or collapsing an
 * extreme aspect ratio to zero — is unit-testable on its own.
 */
final readonly class TransformSpec
{
    /**
     * The output formats callers may request.
     *
     * @var list<string>
     */
    public const array FORMATS = ['webp', 'jpeg', 'png', 'avif'];

    /**
     * The largest dimension bound a caller may ask for, on either axis.
     */
    public const int MAX_DIMENSION = 4096;

    /**
     * The longest edge of the default rendition served over MCP, matched to
     * what a vision model actually consumes — larger images are downsampled by
     * the model anyway, so the extra bytes buy nothing.
     */
    public const int DEFAULT_MAX_EDGE = 1568;

    public const int DEFAULT_QUALITY = 80;

    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public string $format = 'webp',
        public int $quality = self::DEFAULT_QUALITY,
    ) {}

    /**
     * The rendition served over MCP when the caller asked for nothing specific.
     */
    public static function visionDefault(): self
    {
        return new self(
            width: self::DEFAULT_MAX_EDGE,
            height: self::DEFAULT_MAX_EDGE,
            format: 'webp',
            quality: self::DEFAULT_QUALITY,
        );
    }

    /**
     * Whether the caller bounded either axis. A spec without bounds only
     * re-encodes; it never resizes.
     */
    public function boundsGiven(): bool
    {
        return $this->width !== null || $this->height !== null;
    }

    /**
     * The output dimensions for a source of the given size: the image fitted
     * inside the requested box with its aspect ratio preserved.
     *
     * The trailing 1 in the scale factor is what forbids upscaling — an image
     * already inside the box keeps its dimensions. Each axis is floored at 1 so
     * an extreme aspect ratio cannot round away to a zero-pixel edge.
     *
     * @return array{0: int, 1: int}
     */
    public function targetFor(int $sourceWidth, int $sourceHeight): array
    {
        $scale = 1.0;

        if ($this->width !== null) {
            $scale = min($scale, $this->width / $sourceWidth);
        }

        if ($this->height !== null) {
            $scale = min($scale, $this->height / $sourceHeight);
        }

        return [
            max(1, (int) round($sourceWidth * $scale)),
            max(1, (int) round($sourceHeight * $scale)),
        ];
    }

    /**
     * The MIME type of the encoded output.
     */
    public function mimeType(): string
    {
        return 'image/'.$this->format;
    }

    /**
     * The file extension of the encoded output.
     */
    public function extension(): string
    {
        return $this->format === 'jpeg' ? 'jpg' : $this->format;
    }
}
