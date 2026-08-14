<?php

use App\Support\Images\Drivers\GdDriver;
use App\Support\Images\Drivers\ImagickDriver;
use App\Support\Images\ImageTransformer;
use App\Support\Images\TransformSpec;

/**
 * The drivers available on this host, so each behavioural test runs against
 * every decoder rather than only the selected one.
 *
 * @return list<array{0: string}>
 */
dataset('drivers', function (): array {
    $drivers = [['gd']];

    if ((new ImagickDriver)->available()) {
        $drivers[] = ['imagick'];
    }

    return $drivers;
});

function transformerFor(string $driver): ImageTransformer
{
    return new ImageTransformer($driver === 'gd' ? new GdDriver : new ImagickDriver);
}

/**
 * A landscape JPEG carrying an EXIF Orientation tag, hand-assembled as a
 * minimal APP1 segment (TIFF header + one IFD entry) inserted right after the
 * SOI marker — GD's `exif_read_data()` and Imagick's `getImageOrientation()`
 * both read it as a real camera would have written it.
 *
 * Orientation 6 means "rotate 90° CW to display upright": a decoder honouring
 * it reports the image as portrait (height and width swapped) despite the
 * stored pixels being landscape.
 */
function jpegWithOrientation(int $width, int $height, int $orientation): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 50, 50));

    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    $tiff = 'II'.pack('v', 42).pack('V', 8)
        .pack('v', 1) // one IFD entry
        .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0) // Orientation, SHORT, count 1
        .pack('V', 0); // no further IFDs

    $exif = "Exif\0\0".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

it('reads the dimensions of an image', function (string $driver) {
    expect(transformerFor($driver)->dimensions(imageFixture(320, 240)))->toBe([320, 240]);
})->with('drivers');

it('returns null dimensions for bytes that are not an image', function (string $driver) {
    expect(transformerFor($driver)->dimensions('definitely-not-an-image'))->toBeNull();
})->with('drivers');

it('fits an image inside the requested box', function (string $driver) {
    $transformer = transformerFor($driver);

    $output = $transformer->transform(imageFixture(1000, 500), new TransformSpec(width: 200, height: 200));

    expect($output)->not->toBeNull()
        ->and($transformer->dimensions((string) $output))->toBe([200, 100]);
})->with('drivers');

it('scales a tall image on its height', function (string $driver) {
    $transformer = transformerFor($driver);

    $output = $transformer->transform(imageFixture(200, 1600), new TransformSpec(width: 400, height: 400));

    expect($transformer->dimensions((string) $output))->toBe([50, 400]);
})->with('drivers');

it('does not upscale an image that already fits', function (string $driver) {
    $transformer = transformerFor($driver);

    $output = $transformer->transform(imageFixture(100, 80), new TransformSpec(width: 1000, height: 1000));

    expect($transformer->dimensions((string) $output))->toBe([100, 80]);
})->with('drivers');

it('encodes to the requested format', function (string $driver) {
    $transformer = transformerFor($driver);

    $output = (string) $transformer->transform(imageFixture(64, 64), new TransformSpec(format: 'jpeg'));

    expect(getimagesizefromstring($output)[2])->toBe(IMAGETYPE_JPEG);
})->with('drivers');

it('makes a large photo materially smaller', function (string $driver) {
    $transformer = transformerFor($driver);
    $original = imageFixture(3000, 2000);

    $output = (string) $transformer->transform($original, TransformSpec::visionDefault());

    expect(strlen($output))->toBeLessThan(strlen($original))
        ->and(max($transformer->dimensions($output)))->toBe(1568);
})->with('drivers');

it('returns null for bytes it cannot decode', function (string $driver) {
    expect(transformerFor($driver)->transform('definitely-not-an-image', new TransformSpec(width: 100)))->toBeNull();
})->with('drivers');

it('returns null for an unsupported output format', function (string $driver) {
    expect(transformerFor($driver)->transform(imageFixture(64, 64), new TransformSpec(format: 'tiff')))->toBeNull();
})->with('drivers');

it('fits an EXIF-rotated photo by its post-rotation aspect ratio', function (string $driver) {
    $transformer = transformerFor($driver);

    // Stored landscape 100x60, but Orientation 6 means it displays portrait
    // (60x100). Fit that inside a 50x50 box: the height (100) is the binding
    // constraint, so the correct target is 30x50. A driver that resizes using
    // the stale pre-rotation dimensions instead binds on width and stretches
    // the output to 50x30 — swapped, and the wrong aspect ratio for the box.
    $output = $transformer->transform(jpegWithOrientation(100, 60, 6), new TransformSpec(width: 50, height: 50));

    expect($output)->not->toBeNull()
        ->and($transformer->dimensions((string) $output))->toBe([30, 50]);
})->with('drivers');

it('prefers imagick when the extension is loaded', function () {
    $expected = extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class;

    expect((new ImageTransformer)->driver())->toBeInstanceOf($expected);
});
