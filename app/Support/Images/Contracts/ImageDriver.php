<?php

namespace App\Support\Images\Contracts;

use App\Support\Images\ImageTransformer;
use App\Support\Images\TransformSpec;

/**
 * A decoder/encoder backing {@see ImageTransformer}.
 *
 * Every method answers with null rather than throwing, so a caller handed an
 * unreadable upload falls through to a metadata response instead of a 500.
 */
interface ImageDriver
{
    /**
     * Whether this driver's extension is loaded on the current host.
     */
    public function available(): bool;

    /**
     * Whether this driver can encode the given format.
     */
    public function supportsFormat(string $format): bool;

    /**
     * The pixel dimensions of the given bytes, or null when they are not a
     * decodable raster image.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $bytes): ?array;

    /**
     * The image re-rendered to the given spec, or null when the bytes cannot be
     * decoded or the requested format cannot be encoded.
     */
    public function transform(string $bytes, TransformSpec $spec): ?string;
}
