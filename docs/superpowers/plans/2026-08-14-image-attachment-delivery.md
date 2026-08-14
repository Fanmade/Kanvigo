# Image Attachment Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop large image attachments from breaking MCP fetches, and let every consumer request the exact rendition it needs (`width`, `height`, `format`, `quality`) after seeing the image's real dimensions and size.

**Architecture:** One shared `App\Support\Images\ImageTransformer` sits behind every surface that serves image bytes. It picks Imagick when the extension is loaded (HEIC, TIFF, AVIF) and falls back to GD. REST and signed downloads stay byte-exact unless a param is passed; the MCP tool auto-downscales oversized images by default, because that is the path with a hard payload ceiling. Dimensions are stored on the `attachments` row at upload so listings can carry them without opening files.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, `ext-imagick` + `ext-gd` (both present), Laravel MCP v0, Sanctum.

**Spec:** `docs/superpowers/specs/2026-08-14-image-attachment-delivery-design.md`
**Board:** KAN-542 — <https://do.reuterben.de/KAN-542>

## Global Constraints

- **Commit each task, never push.** Commit your task's work straight to `main` (this project is trunk-based — no feature branches) once its tests pass. The commit boundary is what the per-task review is scoped to. Never `git push`; the owner reviews the local history and pushes personally.
- **Pint after every PHP change:** `vendor/bin/pint --dirty --format agent`.
- **Run the minimum tests:** `php artisan test --compact --filter=<name>` or a single file path. Never `php artisan test --testsuite=Browser` directly (see `.ai/browser-tests`); no browser tests are needed for this plan.
- **Static closures:** any closure not using `$this` is declared `static` — except Pest test closures, model factory closures, and `Attribute` accessors. See `.ai/static-closures`.
- **Cheapest condition first** in `&&` / `||` chains, without moving a guard that protects the operand after it. See `.ai/condition-ordering`.
- **Explicit types everywhere:** parameter type hints, return types, PHPDoc array shapes. Curly braces on every control structure.
- **Constants use typed class constants**, matching the existing style: `private const int MAX_INLINE_BYTES = 256 * 1024;`
- **Docs are part of the change**, not a follow-up (`.ai/feature-documentation`).
- **No new user-facing UI strings** in this plan, so no `de.json` edits. If you find yourself adding one, stop — it belongs to the web-routes follow-up (KAN-543).
- **Formats:** `webp` (default), `jpeg`, `png`, `avif`. Dimension bounds clamp to 1–4096.
- **Never upscale.** The scale factor always carries a trailing `min(..., 1)`.

## File Structure

**Create:**
- `app/Support/Images/TransformSpec.php` — immutable value object: the requested bounds, format and quality, plus the arithmetic for turning source dimensions into target dimensions. No I/O, no image library.
- `app/Support/Images/Contracts/ImageDriver.php` — the two-method seam every decoder implements.
- `app/Support/Images/Drivers/GdDriver.php` — GD implementation.
- `app/Support/Images/Drivers/ImagickDriver.php` — Imagick implementation; the only one that can decode HEIC/TIFF.
- `app/Support/Images/ImageTransformer.php` — driver selection and the public entry point every surface calls.
- `app/Http/Controllers/Concerns/ResolvesImageTransforms.php` — shared HTTP param validation → `TransformSpec`.
- `app/Console/Commands/BackfillAttachmentDimensions.php` — one-off backfill for existing rows.
- `database/migrations/*_add_dimensions_to_attachments_table.php`
- `tests/Unit/Images/TransformSpecTest.php`
- `tests/Unit/Images/ImageTransformerTest.php`
- `tests/Feature/Api/V1/AttachmentTransformApiTest.php`
- `tests/Feature/Attachments/SignedDownloadTransformTest.php`
- `tests/Feature/Attachments/BackfillAttachmentDimensionsTest.php`

**Modify:**
- `app/Actions/StoreAttachment.php` — record dimensions at upload.
- `app/Models/Attachment.php` — cast the new columns.
- `app/Http/Resources/AttachmentResource.php` — expose `width` / `height`.
- `app/Http/Controllers/Api/V1/AttachmentController.php` — transform params on `download`, new `metadata` action.
- `app/Http/Controllers/SignedAttachmentDownloadController.php` — transform params.
- `routes/api.php` — the metadata route.
- `routes/web.php` — signature ignore-list on the signed download route.
- `app/Mcp/Tools/GetAttachmentTool.php` — params, auto-downscale, audio ceiling.
- `app/Mcp/Tools/GetTaskTool.php`, `app/Mcp/Tools/GetProjectTool.php` — `size` / `width` / `height` in attachment listings.
- `tests/Pest.php` — the `imageFixture()` helper.
- `docs/developing/api.md`

**Why this shape:** `TransformSpec` holds all the arithmetic and none of the I/O, so the tricky part (fit-inside-a-box, never-upscale) is unit-testable without touching an image library. The drivers hold all the library-specific code and nothing else, so adding a decoder later never touches a caller. `ImageTransformer` is the only name any surface imports.

---

### Task 1: TransformSpec — the box arithmetic

**Files:**
- Create: `app/Support/Images/TransformSpec.php`
- Test: `tests/Unit/Images/TransformSpecTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `TransformSpec` with `public readonly ?int $width`, `?int $height`, `string $format`, `int $quality`; methods `boundsGiven(): bool`, `targetFor(int $sourceWidth, int $sourceHeight): array{0: int, 1: int}`, `mimeType(): string`, `extension(): string`; constants `TransformSpec::FORMATS` (`list<string>`), `TransformSpec::MAX_DIMENSION` (`int`, 4096), `TransformSpec::DEFAULT_MAX_EDGE` (`int`, 1568), `TransformSpec::DEFAULT_QUALITY` (`int`, 80); static factory `TransformSpec::visionDefault(): self`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Images/TransformSpecTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Unit/Images/TransformSpecTest.php`
Expected: FAIL — `Class "App\Support\Images\TransformSpec" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/Images/TransformSpec.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Unit/Images/TransformSpecTest.php`
Expected: PASS, 8 tests.

Note on the expected values: `targetFor(1000, 8000)` with a 1568 box scales by `1568/8000 = 0.196`, giving `round(196.0) = 196` and `1568`. `(new TransformSpec(height: 500))->targetFor(1000, 8000)` scales by `0.0625`, giving `round(62.5) = 63` — PHP's `round()` is half-away-from-zero, so this is 63, not 62.

- [ ] **Step 5: Format**

```bash
vendor/bin/pint --dirty --format agent
```

---

### Task 2: The driver seam — GD and Imagick

**Files:**
- Create: `app/Support/Images/Contracts/ImageDriver.php`
- Create: `app/Support/Images/Drivers/GdDriver.php`
- Create: `app/Support/Images/Drivers/ImagickDriver.php`
- Create: `app/Support/Images/ImageTransformer.php`
- Create: `tests/Unit/Images/ImageTransformerTest.php`
- Modify: `tests/Pest.php`

**Interfaces:**
- Consumes: `TransformSpec` from Task 1.
- Produces:
  - `interface ImageDriver` with `available(): bool`, `supportsFormat(string $format): bool`, `dimensions(string $bytes): ?array{0: int, 1: int}`, `transform(string $bytes, TransformSpec $spec): ?string`.
  - `ImageTransformer` with the same four methods, plus `driver(): ImageDriver`. Constructor takes an optional `?ImageDriver $driver` so tests can force one.
  - `ImageTransformer::BOMB_PIXELS` (`int`, 40_000_000).
  - Test helper `imageFixture(int $width, int $height, string $format = 'png'): string` in `tests/Pest.php`.

Both `dimensions()` and `transform()` return `null` for anything they cannot handle — undecodable bytes, an unsupported output format, or dimensions large enough to look like a decompression bomb. Callers branch on `null`; they never see an exception.

- [ ] **Step 1: Add the image fixture helper**

Append to `tests/Pest.php` (alongside the existing `joinProject()` helper):

```php
/**
 * Raw bytes of a throwaway test image of the given dimensions, filled with a
 * coloured grid so the encoder cannot collapse it to a handful of bytes — tests
 * that assert an image got smaller need it to have had a size to begin with.
 */
function imageFixture(int $width, int $height, string $format = 'png'): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x += 8) {
        for ($y = 0; $y < $height; $y += 8) {
            $colour = imagecolorallocate($image, ($x * 7) % 256, ($y * 13) % 256, (($x + $y) * 3) % 256);
            imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, $colour);
        }
    }

    ob_start();

    match ($format) {
        'jpeg' => imagejpeg($image, null, 92),
        'webp' => imagewebp($image),
        default => imagepng($image),
    };

    return (string) ob_get_clean();
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Images/ImageTransformerTest.php`:

```php
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

it('prefers imagick when the extension is loaded', function () {
    $expected = extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class;

    expect((new ImageTransformer)->driver())->toBeInstanceOf($expected);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact tests/Unit/Images/ImageTransformerTest.php`
Expected: FAIL — `Class "App\Support\Images\Drivers\GdDriver" not found`.

- [ ] **Step 4: Write the contract**

Create `app/Support/Images/Contracts/ImageDriver.php`:

```php
<?php

namespace App\Support\Images\Contracts;

use App\Support\Images\TransformSpec;

/**
 * A decoder/encoder backing {@see \App\Support\Images\ImageTransformer}.
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
```

- [ ] **Step 5: Write the GD driver**

Create `app/Support/Images/Drivers/GdDriver.php`:

```php
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
        if (! $this->available()) {
            return false;
        }

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

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
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
```

- [ ] **Step 6: Write the Imagick driver**

Create `app/Support/Images/Drivers/ImagickDriver.php`:

```php
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
        if (! $this->available()) {
            return false;
        }

        return Imagick::queryFormats(strtoupper($format === 'jpeg' ? 'jpeg' : $format)) !== [];
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

            // Re-read the dimensions after orienting: for EXIF orientations 5-8
            // autoOrient() swaps width and height, and resizeImage() below forces
            // the exact target it is given (no bestfit), so a target computed from
            // the pre-rotation dimensions would stretch the image.
            $image->autoOrient();
            [$targetWidth, $targetHeight] = $spec->targetFor($image->getImageWidth(), $image->getImageHeight());
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
```

- [ ] **Step 7: Write the transformer**

Create `app/Support/Images/ImageTransformer.php`:

```php
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

        if ($imagick->available()) {
            return $this->resolved = $imagick;
        }

        // GD's public methods answer null when its extension is missing, so a
        // host with neither driver degrades to the metadata fallback rather than
        // fatalling on an undefined function.
        return $this->resolved = new GdDriver;
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
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Unit/Images/`
Expected: PASS. The behavioural tests run twice (once per driver) where Imagick is available.

- [ ] **Step 9: Format and type-check**

```bash
vendor/bin/pint --dirty --format agent
composer types:check
```

`composer types:check` runs Larastan across the project — do not run PHPStan against a single file (see the project's Larastan note).

**Do not run `composer check`.** It chains `@test:all`, which runs the Browser suite. That suite starts a Playwright `run-server` and only reaps it if the run completes; an interrupted run orphans the server, which holds the calling process's stdout pipe open and hangs the session. Use `composer test` (lint + types + the Unit and Feature suites) when you want the broad gate, and `composer types:check` when you only want types.

---

### Task 3: Store image dimensions on the attachment row

**Files:**
- Create: `database/migrations/2026_08_14_000000_add_dimensions_to_attachments_table.php`
- Create: `app/Console/Commands/BackfillAttachmentDimensions.php`
- Create: `tests/Feature/Attachments/BackfillAttachmentDimensionsTest.php`
- Modify: `app/Actions/StoreAttachment.php`
- Modify: `app/Models/Attachment.php`

**Interfaces:**
- Consumes: `ImageTransformer::dimensions()` from Task 2.
- Produces: `attachments.width` and `attachments.height` (nullable integers, cast to `integer`); the `attachments:backfill-dimensions` Artisan command.

Storing beats computing: `get-task` lists every attachment on a task, and opening each file per request to measure it would be an N+1 of disk reads.

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration add_dimensions_to_attachments_table --no-interaction
```

Fill it in (note the `static` closures — required by `.ai/static-closures`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', static function (Blueprint $table): void {
            $table->unsignedInteger('width')->nullable()->after('size');
            $table->unsignedInteger('height')->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', static function (Blueprint $table): void {
            $table->dropColumn(['width', 'height']);
        });
    }
};
```

This is an additive `Schema::table()` with no `->change()`, so it does not trip the SQLite functional-index corruption this project has hit before.

- [ ] **Step 2: Cast the columns on the model**

In `app/Models/Attachment.php`, add to the existing `casts()` method (match whatever shape it already uses — `'size' => 'integer'` is likely already there):

```php
'width' => 'integer',
'height' => 'integer',
```

Also add `width` and `height` to `$fillable` if the model uses a `$fillable` array rather than `$guarded`. Check before editing.

- [ ] **Step 3: Write the failing test for upload-time capture**

Create `tests/Feature/Attachments/BackfillAttachmentDimensionsTest.php`:

```php
<?php

use App\Actions\StoreAttachment;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create();
});

it('records the dimensions of an uploaded image', function () {
    $file = UploadedFile::fake()->createWithContent('scan.png', imageFixture(640, 480));

    $attachment = app(StoreAttachment::class)->handle($file, $this->task, uploadedBy: $this->user->id);

    expect($attachment->width)->toBe(640)
        ->and($attachment->height)->toBe(480);
});

it('leaves dimensions null for a non-image upload', function () {
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'plain text');

    $attachment = app(StoreAttachment::class)->handle($file, $this->task, uploadedBy: $this->user->id);

    expect($attachment->width)->toBeNull()
        ->and($attachment->height)->toBeNull();
});

it('backfills dimensions for rows stored before the columns existed', function () {
    Storage::disk('attachments')->put('attachments/old.png', imageFixture(320, 200));

    $attachment = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/old.png',
        'mime_type' => 'image/png',
        'width' => null,
        'height' => null,
    ]);

    $this->artisan('attachments:backfill-dimensions')->assertSuccessful();

    expect($attachment->refresh()->width)->toBe(320)
        ->and($attachment->height)->toBe(200);
});

it('leaves undecodable rows null and still succeeds', function () {
    Storage::disk('attachments')->put('attachments/broken.png', 'not-an-image');

    $attachment = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/broken.png',
        'mime_type' => 'image/png',
        'width' => null,
        'height' => null,
    ]);

    $this->artisan('attachments:backfill-dimensions')->assertSuccessful();

    expect($attachment->refresh()->width)->toBeNull();
});
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Attachments/BackfillAttachmentDimensionsTest.php`
Expected: FAIL — the first test fails on `width` being null, the backfill tests fail with a missing Artisan command.

- [ ] **Step 5: Capture dimensions in StoreAttachment**

In `app/Actions/StoreAttachment.php`, add the import `use App\Support\Images\ImageTransformer;`, then extend the `create()` array. The contents are already read for thumbnail generation, so this costs no extra disk read:

```php
$dimensions = app(ImageTransformer::class)->dimensions((string) $contents);

return $attachable->attachments()->create([
    'disk' => $disk,
    'path' => $path,
    'thumbnail_path' => $this->storeThumbnail((string) $contents, $mimeType, $disk, $directory),
    'name' => $name,
    'mime_type' => $mimeType,
    'size' => $size,
    'width' => $dimensions[0] ?? null,
    'height' => $dimensions[1] ?? null,
    'is_inline' => $isInline,
    'uploaded_by' => $uploadedBy ?? auth()->id(),
]);
```

- [ ] **Step 6: Write the backfill command**

```bash
php artisan make:command BackfillAttachmentDimensions --no-interaction
```

```php
<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Support\Images\ImageTransformer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fills in width/height for attachments stored before those columns existed.
 * Safe to re-run: rows that already carry dimensions, and rows whose bytes no
 * driver can decode, are skipped.
 */
class BackfillAttachmentDimensions extends Command
{
    protected $signature = 'attachments:backfill-dimensions';

    protected $description = 'Measure and store the pixel dimensions of existing image attachments';

    public function handle(ImageTransformer $transformer): int
    {
        $measured = 0;

        Attachment::query()
            ->whereNull('width')
            ->where('mime_type', 'like', 'image/%')
            ->chunkById(100, static function ($attachments) use ($transformer, &$measured): void {
                foreach ($attachments as $attachment) {
                    $disk = Storage::disk($attachment->disk);

                    if (! $disk->exists($attachment->path)) {
                        continue;
                    }

                    $dimensions = $transformer->dimensions((string) $disk->get($attachment->path));

                    if ($dimensions === null) {
                        continue;
                    }

                    $attachment->forceFill(['width' => $dimensions[0], 'height' => $dimensions[1]])->save();
                    $measured++;
                }
            });

        $this->info("Measured {$measured} attachment(s).");

        return self::SUCCESS;
    }
}
```

The chunk closure is `static` because its body never touches `$this` — `$this->info()` is called after the chunking, not inside it. Keep it that way; if you move a `$this` call into the closure, drop the `static`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Attachments/BackfillAttachmentDimensionsTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Run the existing attachment suites for regressions**

Run: `php artisan test --compact --testsuite=Unit,Feature --filter=Attachment`

**The `--testsuite=Unit,Feature` is mandatory, not optional.** A bare `--filter=Attachment` also matches `tests/Browser/AttachmentUploadTest.php` and `AttachmentLightboxTest.php`, which start a Playwright `run-server` that is not reaped and holds the calling process's stdout pipe open — the run never returns and your session stalls. See `.ai/browser-tests`.
Expected: PASS. `StoreAttachment` now writes two more columns; nothing should notice.

- [ ] **Step 9: Format and type-check**

```bash
vendor/bin/pint --dirty --format agent
composer types:check
```

---

### Task 4: Surface size and dimensions in the listings

**Files:**
- Modify: `app/Http/Resources/AttachmentResource.php`
- Modify: `app/Mcp/Tools/GetTaskTool.php:86-91` (payload) and `:148-153` (schema)
- Modify: `app/Mcp/Tools/GetProjectTool.php:81` (payload) and `:126` (schema)
- Test: `tests/Feature/Api/V1/AttachmentsApiTest.php`, `tests/Feature/Mcp/GetTaskToolTest.php`

**Interfaces:**
- Consumes: the `width` / `height` columns from Task 3.
- Produces: `size`, `width`, `height` keys on every attachment object in `get-task`, `get-project` and `AttachmentResource`.

This is the part that actually prevents the wasted request: an agent that has to call a metadata endpoint to decide whether to call a fetch endpoint has still made two calls.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Api/V1/AttachmentsApiTest.php`:

```php
it('reports image dimensions in the attachment listing', function () {
    Attachment::factory()->for($this->task, 'attachable')->create([
        'name' => 'scan.png',
        'mime_type' => 'image/png',
        'size' => 6_291_456,
        'width' => 4000,
        'height' => 3000,
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/tasks/{$this->task->reference}/attachments")
        ->assertOk()
        ->assertJsonPath('data.0.width', 4000)
        ->assertJsonPath('data.0.height', 3000)
        ->assertJsonPath('data.0.size', 6291456);
});
```

Add to `tests/Feature/Mcp/GetTaskToolTest.php` (match the file's existing `beforeEach` variable names — read it first):

```php
it('reports attachment size and dimensions so an agent can budget before fetching', function () {
    Attachment::factory()->for($this->task, 'attachable')->create([
        'name' => 'scan.png',
        'mime_type' => 'image/png',
        'size' => 6_291_456,
        'width' => 4000,
        'height' => 3000,
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetTaskTool::class, ['reference' => $this->task->reference])
        ->assertOk()
        ->assertSee('"size":6291456')
        ->assertSee('"width":4000')
        ->assertSee('"height":3000');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="dimensions"`
Expected: FAIL — missing JSON paths / unseen strings.

- [ ] **Step 3: Extend AttachmentResource**

In `app/Http/Resources/AttachmentResource.php`, add after `'size'`:

```php
'width' => $this->width,
'height' => $this->height,
```

- [ ] **Step 4: Extend the MCP payloads**

In both `app/Mcp/Tools/GetTaskTool.php` and `app/Mcp/Tools/GetProjectTool.php`, extend the attachment mapper:

```php
'attachments' => $task->attachments->map(static fn (Attachment $attachment): array => [
    'id' => $attachment->id,
    'name' => $attachment->name,
    'mime_type' => $attachment->mime_type,
    'size' => $attachment->size,
    'width' => $attachment->width,
    'height' => $attachment->height,
    'is_inline' => $attachment->is_inline,
])->all(),
```

(In `GetProjectTool` the variable is `$project`, not `$task`.)

- [ ] **Step 5: Extend the MCP schemas**

In both tools' `schema()` methods, add to the attachment object:

```php
'size' => $schema->integer()->description('The file size in bytes.')->required(),
'width' => $schema->integer()->nullable()->description('Image width in pixels; null for non-images and files whose dimensions could not be read.'),
'height' => $schema->integer()->nullable()->description('Image height in pixels; null for non-images and files whose dimensions could not be read.'),
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="dimensions"`
Expected: PASS.

- [ ] **Step 7: Run the surrounding suites**

Run: `php artisan test --compact tests/Feature/Api/V1/AttachmentsApiTest.php tests/Feature/Mcp/`
Expected: PASS. If a test asserts an exact attachment JSON structure, extend its expected keys rather than dropping the new fields.

- [ ] **Step 8: Format**

```bash
vendor/bin/pint --dirty --format agent
```

---

### Task 5: The attachment metadata endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/V1/AttachmentController.php`
- Modify: `routes/api.php:71`
- Test: `tests/Feature/Api/V1/AttachmentTransformApiTest.php` (create)

**Interfaces:**
- Consumes: `ImageTransformer::supportsFormat()` / `dimensions()` from Task 2, dimension columns from Task 3.
- Produces: `GET /api/v1/attachments/{attachment}/metadata`, route name `api.v1.attachments.metadata`, returning a flat JSON object (no `data` wrapper is required — match whatever the neighbouring single-resource endpoints do; `AttachmentResource::make()` wraps in `data`, so use that for consistency and add the extra keys).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/V1/AttachmentTransformApiTest.php`:

```php
<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create();

    $this->image = imageFixture(2000, 1500);
    Storage::disk('attachments')->put('attachments/scan.png', $this->image);

    $this->attachment = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/scan.png',
        'name' => 'scan.png',
        'mime_type' => 'image/png',
        'size' => strlen($this->image),
        'width' => 2000,
        'height' => 1500,
    ]);
});

it('returns full metadata for an image attachment', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$this->attachment->id}/metadata")
        ->assertOk()
        ->assertJsonPath('data.name', 'scan.png')
        ->assertJsonPath('data.mime_type', 'image/png')
        ->assertJsonPath('data.width', 2000)
        ->assertJsonPath('data.height', 1500)
        ->assertJsonPath('data.transformable', true);
});

it('reports a non-image attachment as not transformable', function () {
    Storage::disk('attachments')->put('attachments/notes.txt', 'plain text');

    $text = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/notes.txt',
        'mime_type' => 'text/plain',
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$text->id}/metadata")
        ->assertOk()
        ->assertJsonPath('data.transformable', false)
        ->assertJsonPath('data.width', null);
});

it('404s on metadata for an attachment outside the caller projects', function () {
    $outsider = User::factory()->create();
    Sanctum::actingAs($outsider, ['read']);

    $this->getJson("/api/v1/attachments/{$this->attachment->id}/metadata")->assertNotFound();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Api/V1/AttachmentTransformApiTest.php`
Expected: FAIL — 404 on an undefined route.

- [ ] **Step 3: Add the route**

In `routes/api.php`, directly below the existing download route (line 71), inside the same read group:

```php
Route::get('attachments/{attachment}/metadata', [AttachmentController::class, 'metadata'])->whereNumber('attachment')->name('attachments.metadata');
```

Register it **after** `attachments/{attachment}` in the file for readability; the `/metadata` suffix makes the two unambiguous regardless of order.

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/Api/V1/AttachmentController.php`, add the import `use App\Support\Images\ImageTransformer;` and the action:

```php
/**
 * An attachment's full metadata record.
 *
 * Separate from the listing payload because this is where data too heavy to
 * repeat on every attachment of every task belongs — starting with the
 * automatically generated descriptions planned in KAN-544.
 */
public function metadata(int $attachment, ImageTransformer $transformer): JsonResponse
{
    $model = Attachment::find($attachment);

    abort_if($model === null || Auth::user()->cannot('view', $model), 404);

    return AttachmentResource::make($model)
        ->additional(['data' => [
            'transformable' => $model->width !== null && $transformer->supportsFormat('webp'),
        ]])
        ->response();
}
```

`$model->width !== null` is the cheap property check and gates the method call, per the project's condition-ordering rule. A stored width is precisely the signal that some driver decoded this file at upload time.

Note: `->additional(['data' => [...]])` merges into the wrapped payload. If the installed Laravel version merges rather than replaces here, verify with the test; if it replaces, add `transformable` inside `AttachmentResource::toArray()` guarded by `$this->whenNotNull(...)` instead and adjust the listing test to expect it.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Api/V1/AttachmentTransformApiTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Format and type-check**

```bash
vendor/bin/pint --dirty --format agent
composer types:check
```

---

### Task 6: Transform params on the REST download and the signed link

**Files:**
- Create: `app/Http/Controllers/Concerns/ResolvesImageTransforms.php`
- Create: `tests/Feature/Attachments/SignedDownloadTransformTest.php`
- Modify: `app/Http/Controllers/Api/V1/AttachmentController.php` (`download`)
- Modify: `app/Http/Controllers/SignedAttachmentDownloadController.php`
- Modify: `routes/web.php:58-61`
- Test: `tests/Feature/Api/V1/AttachmentTransformApiTest.php`

**Interfaces:**
- Consumes: `TransformSpec`, `ImageTransformer` from Tasks 1–2.
- Produces: trait `ResolvesImageTransforms` with `protected function imageTransformSpec(Request $request): ?TransformSpec` (null when no param was passed) and `protected function transformedAttachmentResponse(Attachment $attachment, TransformSpec $spec, ?User $actor = null): Response`.

Byte-exact stays the default here. A download endpoint that silently re-encoded bytes would be wrong — a `curl` that asked for the file must get the file.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Api/V1/AttachmentTransformApiTest.php`:

```php
it('serves the stored bytes untouched when no transform param is given', function () {
    Sanctum::actingAs($this->user, ['read']);

    $response = $this->get("/api/v1/attachments/{$this->attachment->id}");

    expect($response->streamedContent())->toBe($this->image);
});

it('serves a downscaled rendition when width is given', function () {
    Sanctum::actingAs($this->user, ['read']);

    $response = $this->get("/api/v1/attachments/{$this->attachment->id}?width=400");
    $response->assertOk()->assertHeader('content-type', 'image/webp');

    expect(getimagesizefromstring($response->getContent())[0])->toBe(400);
});

it('bounds a tall image on height', function () {
    Storage::disk('attachments')->put('attachments/tall.png', imageFixture(200, 1600));

    $tall = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/tall.png',
        'name' => 'tall.png',
        'mime_type' => 'image/png',
        'width' => 200,
        'height' => 1600,
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $response = $this->get("/api/v1/attachments/{$tall->id}?height=400");
    $size = getimagesizefromstring($response->getContent());

    expect($size[1])->toBe(400)->and($size[0])->toBe(50);
});

it('honours the requested format and names the file accordingly', function () {
    Sanctum::actingAs($this->user, ['read']);

    $response = $this->get("/api/v1/attachments/{$this->attachment->id}?width=200&format=jpeg");

    $response->assertOk()->assertHeader('content-type', 'image/jpeg');
    expect($response->headers->get('content-disposition'))->toContain('scan.jpg')
        ->and(getimagesizefromstring($response->getContent())[2])->toBe(IMAGETYPE_JPEG);
});

it('rejects an out-of-range dimension', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$this->attachment->id}?width=99999")
        ->assertStatus(422)
        ->assertJsonValidationErrors('width');
});

it('rejects an unknown format', function () {
    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$this->attachment->id}?format=bmp")
        ->assertStatus(422)
        ->assertJsonValidationErrors('format');
});

it('rejects transform params on a non-image attachment', function () {
    Storage::disk('attachments')->put('attachments/spec.pdf', 'pdf-bytes');

    $pdf = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/spec.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Sanctum::actingAs($this->user, ['read']);

    $this->getJson("/api/v1/attachments/{$pdf->id}?width=200")->assertStatus(422);
});
```

Create `tests/Feature/Attachments/SignedDownloadTransformTest.php`:

```php
<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('attachments.disk', 'attachments');
    Storage::fake('attachments');

    $this->member = User::factory()->create();
    $this->project = Project::factory()->withMembers([$this->member])->create(['short_name' => 'ABC']);
    $this->task = Task::factory()->for($this->project)->create();

    Storage::disk('attachments')->put('attachments/scan.png', imageFixture(2000, 1500));

    $this->attachment = Attachment::factory()->for($this->task, 'attachable')->create([
        'disk' => 'attachments',
        'path' => 'attachments/scan.png',
        'name' => 'scan.png',
        'mime_type' => 'image/png',
        'width' => 2000,
        'height' => 1500,
    ]);
});

it('accepts transform params appended to an already-issued signed link', function () {
    // The link is minted without params — exactly as the MCP tool hands it out.
    $url = $this->attachment->signedDownloadUrl($this->member);

    $response = $this->get($url.'&width=300');

    $response->assertOk();
    expect(getimagesizefromstring($response->getContent())[0])->toBe(300);
});

it('still rejects a link whose attachment was swapped', function () {
    $other = Attachment::factory()->for($this->task, 'attachable')->create();
    $url = $this->attachment->signedDownloadUrl($this->member);

    $this->get(str_replace("/{$this->attachment->id}/", "/{$other->id}/", $url))->assertForbidden();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Api/V1/AttachmentTransformApiTest.php tests/Feature/Attachments/SignedDownloadTransformTest.php`
Expected: FAIL — params are ignored, so the transform assertions fail; the signed-link-with-params test fails with a 403 from `ValidateSignature`.

- [ ] **Step 3: Write the shared trait**

Create `app/Http/Controllers/Concerns/ResolvesImageTransforms.php`:

```php
<?php

namespace App\Http\Controllers\Concerns;

use App\Audit\AccessAudit;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Facades\Audit;
use App\Support\Images\ImageTransformer;
use App\Support\Images\TransformSpec;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared handling of the `width` / `height` / `format` / `quality` query
 * parameters accepted by every surface that serves image bytes.
 *
 * Absent parameters mean "give me the file", so the caller streams the stored
 * bytes untouched. Any parameter is an explicit opt-in to a re-encode.
 */
trait ResolvesImageTransforms
{
    /**
     * The rendition the request asked for, or null when it asked for none.
     */
    protected function imageTransformSpec(Request $request): ?TransformSpec
    {
        $validated = $request->validate([
            'width' => ['sometimes', 'integer', 'min:1', 'max:'.TransformSpec::MAX_DIMENSION],
            'height' => ['sometimes', 'integer', 'min:1', 'max:'.TransformSpec::MAX_DIMENSION],
            'format' => ['sometimes', 'string', Rule::in(TransformSpec::FORMATS)],
            'quality' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validated === []) {
            return null;
        }

        $format = $validated['format'] ?? 'webp';

        if (! app(ImageTransformer::class)->supportsFormat($format)) {
            throw ValidationException::withMessages([
                'format' => 'The "'.$format.'" format cannot be encoded on this server.',
            ]);
        }

        return new TransformSpec(
            width: $validated['width'] ?? null,
            height: $validated['height'] ?? null,
            format: $format,
            quality: $validated['quality'] ?? TransformSpec::DEFAULT_QUALITY,
        );
    }

    /**
     * The transformed image as a download response, audited like any other
     * attachment read. A file the active driver cannot decode is a 422 rather
     * than a silent byte-exact fallback — the caller asked for a rendition and
     * has to know it did not get one.
     */
    protected function transformedAttachmentResponse(Attachment $attachment, TransformSpec $spec, ?User $actor = null): Response
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $rendered = app(ImageTransformer::class)->transform((string) $disk->get($attachment->path), $spec);

        if ($rendered === null) {
            throw ValidationException::withMessages([
                'width' => 'This attachment is not an image that can be transformed.',
            ]);
        }

        $event = AccessAudit::attachmentDownloaded($attachment);
        Audit::record($actor === null ? $event : $event->withActor($actor->getKey()));

        $name = pathinfo($attachment->name, PATHINFO_FILENAME).'.'.$spec->extension();

        return response($rendered, 200, [
            'Content-Type' => $spec->mimeType(),
            'Content-Length' => (string) strlen($rendered),
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
```

- [ ] **Step 4: Wire the REST download**

In `app/Http/Controllers/Api/V1/AttachmentController.php`, add `use App\Http\Controllers\Concerns\ResolvesImageTransforms;` to the imports and `use ResolvesImageTransforms;` to the class body, then replace `download()`:

```php
/**
 * Stream an attachment's file content as a download.
 *
 * Byte-exact by default. Passing any of width/height/format/quality opts into
 * a re-encoded rendition instead.
 */
public function download(Request $request, int $attachment): StreamedResponse|Response
{
    $model = Attachment::find($attachment);

    abort_if($model === null || Auth::user()->cannot('view', $model), 404);

    $spec = $this->imageTransformSpec($request);

    if ($spec === null) {
        return $this->downloadAttachment($model);
    }

    return $this->transformedAttachmentResponse($model, $spec);
}
```

Add `use Illuminate\Http\Response;` to the imports for the union return type.

- [ ] **Step 5: Wire the signed download**

Replace the body of `app/Http/Controllers/SignedAttachmentDownloadController.php`'s `__invoke`, adding `use ResolvesImageTransforms;` to the class and the matching import plus `Illuminate\Http\Request` and `Illuminate\Http\Response`:

```php
public function __invoke(Request $request, Attachment $attachment, User $user): StreamedResponse|Response
{
    abort_if(Gate::forUser($user)->denies('view', $attachment), 404);

    $spec = $this->imageTransformSpec($request);

    if ($spec === null) {
        return $this->downloadAttachment($attachment, $user);
    }

    return $this->transformedAttachmentResponse($attachment, $spec, $user);
}
```

- [ ] **Step 6: Let the transform params ride outside the signature**

In `routes/web.php`, replace `->middleware('signed')` on the `attachments.signed-download` route:

```php
Route::get('attachments/{attachment}/download/{user}', SignedAttachmentDownloadController::class)
    ->middleware(ValidateSignature::absolute(['width', 'height', 'format', 'quality']))
    ->whereNumber('attachment')
    ->name('attachments.signed-download');
```

Add `use Illuminate\Routing\Middleware\ValidateSignature;` to the imports.

Laravel signs the whole query string, so without this an agent appending `?width=1024` to a link it was handed would get a 403. Leaving these four unsigned is safe: they cannot change which attachment is served or which user it was issued for, and each is clamped by validation before use. Extend the route's doc comment to say so.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Api/V1/AttachmentTransformApiTest.php tests/Feature/Attachments/SignedDownloadTransformTest.php`
Expected: PASS.

- [ ] **Step 8: Run every attachment-touching suite**

Run: `php artisan test --compact --testsuite=Unit,Feature --filter=Attachment`

**The `--testsuite=Unit,Feature` is mandatory, not optional.** A bare `--filter=Attachment` also matches `tests/Browser/AttachmentUploadTest.php` and `AttachmentLightboxTest.php`, which start a Playwright `run-server` that is not reaped and holds the calling process's stdout pipe open — the run never returns and your session stalls. See `.ai/browser-tests`.
Expected: PASS — in particular the existing signed-download and audit tests, which must be unaffected when no params are passed.

- [ ] **Step 9: Format and type-check**

```bash
vendor/bin/pint --dirty --format agent
composer types:check
```

---

### Task 7: The MCP fix — safe default, opt-out params, audio ceiling

**Files:**
- Modify: `app/Mcp/Tools/GetAttachmentTool.php`
- Test: `tests/Feature/Mcp/GetAttachmentToolTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–3.
- Produces: `get-attachment` accepting optional `width`, `height`, `format`, `quality` alongside the existing `id` and `offset`.

This is the task that closes KAN-542.

**The decision table this implements** — for an `image/*` attachment:

| Situation | Result |
| --- | --- |
| Caller passed any transform param | Transform to exactly that spec |
| No params, longest edge > 1568 px **or** stored size > 512 KiB | Transform to the vision default (1568 box, WebP, q80) |
| No params, image inside both thresholds | Stored bytes, untouched |
| Transform attempted, driver could not decode, bytes ≤ 2 MiB | Stored bytes, untouched |
| Transform attempted, driver could not decode, bytes > 2 MiB | Metadata + signed URL, no image block |
| Result still > 2 MiB after transform | Metadata + signed URL, no image block |

The "could not decode but small" row is deliberate: a tiny unreadable file is harmless to inline and refusing it would break callers (and existing tests) that store trivial placeholder bytes. A large unreadable file is exactly the HEIC/TIFF case that must not be shipped.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Mcp/GetAttachmentToolTest.php`:

```php
/**
 * Store an image attachment on the test task and return it.
 */
function imageAttachment(string $bytes, string $path = 'attachments/scan.png'): Attachment
{
    Storage::disk('attachments')->put($path, $bytes);
    $dimensions = app(\App\Support\Images\ImageTransformer::class)->dimensions($bytes);

    return Attachment::factory()->create([
        'attachable_id' => test()->task->id,
        'attachable_type' => test()->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => $path,
        'name' => basename($path),
        'mime_type' => 'image/png',
        'size' => strlen($bytes),
        'width' => $dimensions[0] ?? null,
        'height' => $dimensions[1] ?? null,
    ]);
}

it('downscales a large image instead of inlining megabytes of base64', function () {
    $original = imageFixture(4000, 3000);
    $attachment = imageAttachment($original);

    $response = KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk();

    $response->assertSee('downscaled');
    $response->assertDontSee(base64_encode($original));
});

it('downscales a tall image, which a width-only cap would have missed', function () {
    $attachment = imageAttachment(imageFixture(1000, 8000), 'attachments/tall.png');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('downscaled');
});

it('returns a small image untouched', function () {
    $original = imageFixture(200, 150);
    $attachment = imageAttachment($original, 'attachments/small.png');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee(base64_encode($original));
});

it('honours explicit transform params', function () {
    $attachment = imageAttachment(imageFixture(2000, 1500));

    $response = KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id, 'width' => 300, 'format' => 'jpeg'])
        ->assertOk();

    $response->assertSee('image/jpeg');
});

it('falls back to metadata for a large image no driver can decode', function () {
    // 3 MB of bytes that are not a decodable image — the HEIC/TIFF case.
    $attachment = imageAttachment(str_repeat('x', 3 * 1024 * 1024), 'attachments/scan.heic');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('cannot be displayed inline')
        ->assertSee('signed URL');
});

it('refuses to inline an oversized audio file', function () {
    Storage::disk('attachments')->put('attachments/long.mp3', str_repeat('a', 5 * 1024 * 1024));

    $attachment = Attachment::factory()->create([
        'attachable_id' => $this->task->id,
        'attachable_type' => $this->task->getMorphClass(),
        'disk' => 'attachments',
        'path' => 'attachments/long.mp3',
        'name' => 'long.mp3',
        'mime_type' => 'audio/mpeg',
        'size' => 5 * 1024 * 1024,
    ]);

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk()
        ->assertSee('too large to return inline')
        ->assertSee('signed URL');
});

it('does not audit a content read when it only returns metadata', function () {
    $attachment = imageAttachment(str_repeat('x', 3 * 1024 * 1024), 'attachments/scan.heic');

    KanvigoServer::actingAs($this->member)
        ->tool(GetAttachmentTool::class, ['id' => $attachment->id])
        ->assertOk();

    expect(attachmentDownloadAudits())->toBeEmpty();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Mcp/GetAttachmentToolTest.php`
Expected: FAIL on the new cases; the pre-existing cases still pass.

- [ ] **Step 3: Add the constants and dependencies**

In `app/Mcp/Tools/GetAttachmentTool.php`, add the imports:

```php
use App\Support\Images\ImageTransformer;
use App\Support\Images\TransformSpec;
```

and the constants beside `MAX_INLINE_BYTES`:

```php
/**
 * Above either of these an image is downscaled before it is inlined. Byte size
 * catches a heavily-detailed small image; the edge length catches a long, thin
 * scan that is modest on one axis and enormous on the other.
 */
private const int AUTO_TRANSFORM_BYTES = 512 * 1024;

/**
 * The largest encoded image returned inline. Anything still above this after a
 * transform is handed over as a link instead — an oversized base64 payload is
 * what breaks the client in the first place.
 */
private const int MAX_INLINE_IMAGE_BYTES = 2 * 1024 * 1024;

/**
 * Audio cannot be transformed, so it is simply gated: past this it becomes a
 * link. Same defect as oversized images, same remedy.
 */
private const int MAX_INLINE_AUDIO_BYTES = 4 * 1024 * 1024;
```

- [ ] **Step 4: Extend validation and the schema**

In `handle()`, extend the validation array:

```php
$validated = $request->validate([
    'id' => ['required', 'integer'],
    'offset' => ['sometimes', 'integer', 'min:0'],
    'width' => ['sometimes', 'integer', 'min:1', 'max:'.TransformSpec::MAX_DIMENSION],
    'height' => ['sometimes', 'integer', 'min:1', 'max:'.TransformSpec::MAX_DIMENSION],
    'format' => ['sometimes', 'string', 'in:'.implode(',', TransformSpec::FORMATS)],
    'quality' => ['sometimes', 'integer', 'min:1', 'max:100'],
], [
    'id.required' => 'You must provide the attachment id. Attachment ids are listed by the get-project and get-task tools.',
]);
```

And in `schema()`:

```php
'width' => $schema->integer()->description('For images, the maximum width in pixels of the returned rendition (1–4096). The image is fitted inside the width/height box with its aspect ratio preserved and is never enlarged.'),
'height' => $schema->integer()->description('For images, the maximum height in pixels of the returned rendition (1–4096). Give this for tall images — a page scan can be narrow and still enormous.'),
'format' => $schema->string()->description('For images, the encoding of the returned rendition: webp (default), jpeg, png or avif.'),
'quality' => $schema->integer()->description('For images, the encoder quality from 1 to 100 (default 80). Ignored for png.'),
```

- [ ] **Step 5: Replace the image branch**

Replace the `$isImage` handling in `handle()`. The audit call must move below the transform, so that the metadata fallthrough records no content read:

```php
if ($isImage) {
    return $this->imageResponse($attachment, $contents, $mimeType, $validated, $downloadLink);
}

if ($isAudio) {
    if (strlen($contents) > self::MAX_INLINE_AUDIO_BYTES) {
        return Response::make([
            Response::text('Attachment "'.$attachment->name.'" ('.$attachment->size.' bytes) is too large to return inline. Fetch it from the signed URL below.'),
            $downloadLink,
        ]);
    }

    Audit::record(AccessAudit::attachmentDownloaded($attachment));

    return Response::make([Response::audio($contents, $mimeType), $downloadLink]);
}
```

Delete the old combined `if ($isImage || $isAudio || $isInlineText)` audit block and record the inline-text audit inside its own branch instead:

```php
if ($isInlineText) {
    Audit::record(AccessAudit::attachmentDownloaded($attachment));

    return Response::make([
        Response::text($this->inlineText($attachment, $contents, $validated['offset'] ?? 0)),
        $downloadLink,
    ]);
}
```

- [ ] **Step 6: Add the image response method**

Add to `GetAttachmentTool`:

```php
/**
 * The response for an image attachment.
 *
 * A caller that named a rendition gets exactly that. A caller that named
 * nothing gets the stored bytes when they are small enough to inline safely,
 * and a vision-sized WebP when they are not — an untransformed multi-megabyte
 * image is what breaks the client, and a model downsamples past 1568 px on its
 * side regardless, so the full-resolution bytes buy nothing.
 *
 * @param  array<string, mixed>  $validated
 */
private function imageResponse(Attachment $attachment, string $contents, string $mimeType, array $validated, Response $downloadLink): Response
{
    $transformer = app(ImageTransformer::class);
    $requested = $this->requestedSpec($validated);
    $dimensions = $transformer->dimensions($contents);
    $notice = null;

    $spec = $requested;

    if ($spec === null) {
        $tooLarge = strlen($contents) > self::AUTO_TRANSFORM_BYTES
            || ($dimensions !== null && max($dimensions) > TransformSpec::DEFAULT_MAX_EDGE);

        $spec = $tooLarge ? TransformSpec::visionDefault() : null;
    }

    if ($spec !== null) {
        $rendered = $transformer->transform($contents, $spec);

        if ($rendered !== null) {
            $notice = 'This image was downscaled to fit '.$spec->width.'×'.$spec->height.' as '.$spec->format
                .'. The original is '.($dimensions === null ? 'of unknown size' : $dimensions[0].'×'.$dimensions[1])
                .' and '.$attachment->size.' bytes — fetch the signed URL below for it untouched.';
            $contents = $rendered;
            $mimeType = $spec->mimeType();
        }
    }

    if (strlen($contents) > self::MAX_INLINE_IMAGE_BYTES) {
        return Response::make([
            Response::text('Attachment "'.$attachment->name.'" ('.$mimeType.', '.$attachment->size.' bytes) cannot be displayed inline: it is too large, and this server could not re-encode it to a smaller rendition. Fetch it from the signed URL below.'),
            $downloadLink,
        ]);
    }

    Audit::record(AccessAudit::attachmentDownloaded($attachment));

    $blocks = [Response::image($contents, $mimeType)];

    if ($notice !== null) {
        $blocks[] = Response::text($notice);
    }

    $blocks[] = $downloadLink;

    return Response::make($blocks);
}

/**
 * The rendition the caller asked for, or null when they asked for none.
 *
 * @param  array<string, mixed>  $validated
 */
private function requestedSpec(array $validated): ?TransformSpec
{
    $keys = ['width', 'height', 'format', 'quality'];

    if (array_intersect_key($validated, array_flip($keys)) === []) {
        return null;
    }

    return new TransformSpec(
        width: $validated['width'] ?? null,
        height: $validated['height'] ?? null,
        format: $validated['format'] ?? 'webp',
        quality: $validated['quality'] ?? TransformSpec::DEFAULT_QUALITY,
    );
}
```

Note the ordering in `$tooLarge`: the `strlen()` comparison is the cheap operand and short-circuits before the `max()` call, per `.ai/condition-ordering`.

- [ ] **Step 7: Update the tool description**

Replace the `#[Description(...)]` attribute text so agents learn the new behaviour. Keep it one paragraph, matching the existing voice:

```php
#[Description('Gets the content of an attachment by its id, including inline images embedded in a project or task description. Image and audio attachments are returned as viewable content, and text-based attachments (logs, JSON, XML, CSV, …) are returned as text — up to 256 KiB per call, with an optional "offset" to page through larger files. Large images are returned downscaled to a vision-sized rendition by default so the response stays small; pass "width" and/or "height" (the image is fitted inside that box, aspect ratio preserved, never enlarged) plus optional "format" (webp, jpeg, png, avif) and "quality" to choose a rendition yourself. The get-task and get-project tools report each attachment size and pixel dimensions, so check those before asking for a smaller version of an already-small image. Other file types return their metadata only. Every response also carries a short-lived signed URL the raw file can be downloaded from with a plain HTTP request (no credentials), so an agent can write the original bytes to disk — the transform parameters work on that URL too. Attachment ids are listed by the get-project and get-task tools. Only attachments in projects the authenticated user is a member of are accessible.')]
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Mcp/GetAttachmentToolTest.php`
Expected: PASS, including every pre-existing test in the file. If the pre-existing "returns the image content of an inline attachment" test fails, the undecodable-but-small fallthrough is wrong — it stores the literal bytes `png-bytes`, which must still come back verbatim.

- [ ] **Step 9: Run the whole MCP suite**

Run: `php artisan test --compact tests/Feature/Mcp/`
Expected: PASS.

- [ ] **Step 10: Format and type-check**

```bash
vendor/bin/pint --dirty --format agent
composer types:check
```

---

### Task 8: Documentation

**Files:**
- Modify: `docs/developing/api.md`
- Modify: `README.md` (only if its feature list mentions attachment delivery)

**Interfaces:**
- Consumes: the finished behaviour from Tasks 5–7.
- Produces: no code.

Undocumented behaviour counts as incomplete work here (`.ai/feature-documentation`). Describe what the feature does, not how it is built — no class names, no driver mechanics.

- [ ] **Step 1: Read the surrounding documentation**

Read `docs/developing/api.md` in full and find the attachments section. Match its heading depth, table style and tone exactly.

- [ ] **Step 2: Document the transform parameters**

Add to the attachments section, adapted to the file's existing formatting:

> `GET /api/v1/attachments/{id}` returns the stored file unchanged. For images you can request a rendition instead by passing any of `width`, `height` (1–4096 pixels), `format` (`webp`, `jpeg`, `png`, `avif`) and `quality` (1–100, default 80). The image is fitted inside the width/height box with its aspect ratio preserved and is never enlarged; give `height` for tall images, which a width bound alone leaves untouched. Passing a transform parameter for a non-image attachment is a 422. The same parameters work on the short-lived signed download links.

- [ ] **Step 3: Document the metadata endpoint**

> `GET /api/v1/attachments/{id}/metadata` returns an attachment's full record — name, MIME type, byte size, pixel dimensions and whether the server can re-encode it. Attachment listings already carry size and dimensions, so an agent can decide what to fetch without this call; the endpoint exists for detail too heavy to repeat in every listing.

- [ ] **Step 4: Apply the Boy Scout rule**

While in `docs/developing/api.md`, fix anything stale you notice in the attachments section — a removed field still listed, an inconsistent term, a broken reference — even if unrelated to this change.

- [ ] **Step 5: Verify no translation work was missed**

Run: `php artisan test --compact --filter=TranslationCompleteness`
Expected: PASS. This plan adds no user-facing UI strings; a failure means one crept in and needs a `de.json` entry.

- [ ] **Step 6: Full verification**

```bash
composer test
```

`composer test` is lint + types + the Unit and Feature suites. Do **not** use `composer check` here: it chains `@test:all`, which runs the Browser suite, and an interrupted browser run orphans a Playwright server that hangs the session. If the browser suite genuinely needs running, it is `composer test:browser` — which self-reaps — and never bare artisan.

Expected: PASS, apart from the 4 pre-existing `DocumentationIndexTest` failures that predate this work. Report the actual output — if anything else fails, say so with the failure rather than reporting completion.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
| --- | --- |
| Transform service with driver seam | 2 |
| Imagick preferred, GD fallback, metadata fallthrough | 2, 7 |
| `width` / `height` box semantics, never upscale | 1 |
| Format allow-list, `avif` only when encodable | 1, 6 |
| Bomb guard reproduced in drivers | 2 |
| REST/signed byte-exact by default, transform on param | 6 |
| Signature ignore-list for the four params | 6 |
| MCP safe default at 1568 / 512 KiB thresholds | 7 |
| MCP explicit params override | 7 |
| Undecodable and >2 MiB fallbacks | 7 |
| Audio ceiling | 7 |
| Stored dimensions + backfill | 3 |
| `size` / `width` / `height` in listings | 4 |
| Metadata endpoint with `transformable` | 5 |
| Full test list from the spec | 1–7 |
| Docs | 8 |

Every spec section maps to a task. No gaps.

**Placeholder scan:** No TBDs, no "add error handling", no "similar to Task N". Every code step carries the actual code. Two steps carry a verify-then-adjust instruction (the `->additional()` wrapping in Task 5, the closure-static note in Task 3) — both name the exact check and the exact alternative, so neither is a placeholder.

**Type consistency:** `TransformSpec` is constructed with the same four named arguments in Tasks 1, 6 and 7. `ImageTransformer::transform()`, `dimensions()` and `supportsFormat()` keep identical signatures across Tasks 2, 3, 5, 6 and 7. `imageFixture()` is defined once in Task 2 and used in Tasks 3, 5, 6 and 7. `TransformSpec::DEFAULT_MAX_EDGE` is referenced in Tasks 1 and 7 under that one name.

**One refinement of the spec, made explicit in Task 7:** the spec says undecodable bytes fall through to metadata. The plan qualifies that by size — undecodable *and* over 2 MiB becomes a link; undecodable and small is served as stored. Without the qualifier, existing tests that store placeholder bytes like `png-bytes` would break, and refusing a tiny unreadable file buys nothing. Update the spec's error table to match when this lands.
