<?php

use App\Support\Attachments\UploadLimit;
use Tests\TestCase;

// The limit reads the configured cap from config(), so the container must be booted.
uses(TestCase::class);

beforeEach(function () {
    config()->set('attachments.max_size', 12288);
});

it('uses the configured cap when PHP allows at least that much', function () {
    $limit = new UploadLimit(uploadMaxFilesize: 16 * 1024 * 1024, postMaxSize: 20 * 1024 * 1024);

    expect($limit->kilobytes())->toBe(12288)
        ->and($limit->bytes())->toBe(12288 * 1024);
});

it('clamps to upload_max_filesize when PHP accepts smaller files than the app', function () {
    $limit = new UploadLimit(uploadMaxFilesize: 2 * 1024 * 1024, postMaxSize: 8 * 1024 * 1024);

    expect($limit->kilobytes())->toBe(2048);
});

it('clamps below post_max_size so a maximum-size request still fits with its multipart overhead', function () {
    $limit = new UploadLimit(uploadMaxFilesize: 16 * 1024 * 1024, postMaxSize: 8 * 1024 * 1024);

    expect($limit->bytes())->toBe(8 * 1024 * 1024 - UploadLimit::POST_HEADROOM_BYTES)
        ->and($limit->kilobytes())->toBe(8192 - UploadLimit::POST_HEADROOM_BYTES / 1024);
});

it('treats a non-positive PHP limit as unlimited', function () {
    $limit = new UploadLimit(uploadMaxFilesize: 0, postMaxSize: -1);

    expect($limit->kilobytes())->toBe(12288);
});

it('follows the configured cap when it is lowered at runtime', function () {
    $limit = new UploadLimit(uploadMaxFilesize: 16 * 1024 * 1024, postMaxSize: 20 * 1024 * 1024);

    config()->set('attachments.max_size', 100);

    expect($limit->kilobytes())->toBe(100);
});

it('labels the effective limit in megabytes or kilobytes', function (int $configuredKb, string $label) {
    config()->set('attachments.max_size', $configuredKb);

    $limit = new UploadLimit(uploadMaxFilesize: null, postMaxSize: null);

    expect($limit->label())->toBe($label);
})->with([
    'whole megabytes' => [12288, '12 MB'],
    'fractional megabytes' => [1536, '1.5 MB'],
    'kilobytes' => [512, '512 KB'],
]);

it('reads the PHP limits from the loaded ini', function () {
    $limit = UploadLimit::fromIni();

    $expected = min(
        12288,
        intdiv(ini_parse_quantity(ini_get('upload_max_filesize')), 1024),
        intdiv(ini_parse_quantity(ini_get('post_max_size')) - UploadLimit::POST_HEADROOM_BYTES, 1024),
    );

    expect($limit->kilobytes())->toBe($expected);
});
