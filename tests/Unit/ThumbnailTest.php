<?php

use App\Support\Thumbnail;
use Tests\TestCase;

// PDF rasterization uses the Process facade and config(), so the application
// container must be booted for these tests.
uses(TestCase::class);

it('creates a downscaled PNG thumbnail from a large image', function () {
    $image = imagecreatetruecolor(600, 400);

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    $thumbnail = Thumbnail::generate($bytes, 'image/png');

    expect($thumbnail)->not->toBeNull();

    [$width, $height] = getimagesizefromstring($thumbnail);

    expect(max($width, $height))->toBe(Thumbnail::MAX_DIMENSION);
});

it('rasterizes the first page of a PDF into a thumbnail', function () {
    $pdf = new Imagick;
    $pdf->newImage(800, 1000, new ImagickPixel('skyblue'));
    $pdf->setImageFormat('pdf');
    $bytes = $pdf->getImageBlob();
    $pdf->clear();

    $thumbnail = Thumbnail::generate($bytes, 'application/pdf');

    expect($thumbnail)->not->toBeNull();

    $info = getimagesizefromstring($thumbnail);

    expect($info[2])->toBe(IMAGETYPE_PNG)
        ->and(max($info[0], $info[1]))->toBe(Thumbnail::MAX_DIMENSION);
});

it('rasterizes a photo-sized PDF page quickly without exploding the raster', function () {
    $photo = new Imagick;
    $photo->newPseudoImage(4000, 3000, 'plasma:fractal');
    $photo->setImageFormat('pdf');
    $bytes = $photo->getImageBlob();
    $photo->clear();

    $start = microtime(true);
    $thumbnail = Thumbnail::generate($bytes, 'application/pdf');
    $elapsed = microtime(true) - $start;

    expect($thumbnail)->not->toBeNull()
        ->and($elapsed)->toBeLessThan(5.0);

    [$width, $height] = getimagesizefromstring($thumbnail);

    expect(max($width, $height))->toBe(Thumbnail::MAX_DIMENSION);
});

it('returns null for content that is neither an image nor a PDF', function () {
    expect(Thumbnail::generate('this is just text', 'text/plain'))->toBeNull()
        ->and(Thumbnail::generate('not a pdf at all', 'application/pdf'))->toBeNull();
});
