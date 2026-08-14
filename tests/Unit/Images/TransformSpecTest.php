<?php

use App\Support\Images\TransformSpec;

it('fits a wide image inside the box, preserving aspect ratio', function () {
    $spec = new TransformSpec(width: 1568, height: 1568);

    expect($spec->targetFor(4000, 3000))->toBe([1568, 1176]);
});

it('scales a tall image on its height, which a width-only bound would have missed', function () {
    $spec = new TransformSpec(width: 1568, height: 1568);

    // 1000x8000 is already inside a 1568px *width* limit — height is what binds.
    expect($spec->targetFor(1000, 8000))->toBe([196, 1568]);
});

it('bounds a single axis when only one is given', function () {
    expect((new TransformSpec(width: 500))->targetFor(1000, 8000))->toBe([500, 4000])
        ->and((new TransformSpec(height: 500))->targetFor(1000, 8000))->toBe([63, 500]);
});

it('never upscales an image that already fits', function () {
    $spec = new TransformSpec(width: 4000, height: 4000);

    expect($spec->targetFor(800, 600))->toBe([800, 600]);
});

it('never returns a zero dimension for an extreme aspect ratio', function () {
    $spec = new TransformSpec(width: 100, height: 100);

    expect($spec->targetFor(10000, 5))->toBe([100, 1]);
});

it('reports whether any dimension bound was requested', function () {
    expect((new TransformSpec)->boundsGiven())->toBeFalse()
        ->and((new TransformSpec(width: 100))->boundsGiven())->toBeTrue()
        ->and((new TransformSpec(height: 100))->boundsGiven())->toBeTrue();
});

it('maps each format to its mime type and extension', function (string $format, string $mime, string $extension) {
    $spec = new TransformSpec(format: $format);

    expect($spec->mimeType())->toBe($mime)
        ->and($spec->extension())->toBe($extension);
})->with([
    ['webp', 'image/webp', 'webp'],
    ['jpeg', 'image/jpeg', 'jpg'],
    ['png', 'image/png', 'png'],
    ['avif', 'image/avif', 'avif'],
]);

it('defaults to a vision-sized webp box', function () {
    $spec = TransformSpec::visionDefault();

    expect($spec->width)->toBe(1568)
        ->and($spec->height)->toBe(1568)
        ->and($spec->format)->toBe('webp')
        ->and($spec->quality)->toBe(80);
});
