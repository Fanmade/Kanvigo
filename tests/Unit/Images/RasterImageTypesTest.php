<?php

use App\Support\Images\RasterImageTypes;

it('accepts the raster formats the drivers decode', function (string $mimeType) {
    expect(RasterImageTypes::isDecodable($mimeType))->toBeTrue();
})->with(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/tiff', 'image/bmp']);

it('refuses delegate-backed and non-image types', function (?string $mimeType) {
    expect(RasterImageTypes::isDecodable($mimeType))->toBeFalse();
})->with([
    // The whole point of the allow-list: these all satisfy "image/*" (or are
    // decodable by Imagick) yet reach librsvg, Ghostscript or an interpreter.
    'image/svg+xml',
    'image/svg',
    'image/x-eps',
    'image/x-mvg',
    'image/x-msl',
    'application/pdf',
    'application/postscript',
    'text/plain',
    '',
    null,
]);

it('matches the type case- and whitespace-insensitively', function () {
    expect(RasterImageTypes::isDecodable(' IMAGE/PNG '))->toBeTrue();
});
