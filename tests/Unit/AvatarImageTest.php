<?php

use App\Support\Avatar;

test('it crops a non-square image to a centred square of the avatar size', function () {
    ob_start();
    imagepng(imagecreatetruecolor(600, 200));
    $png = (string) ob_get_clean();

    $result = Avatar::fromImage($png);

    expect($result)->not->toBeNull();

    [$width, $height] = getimagesizefromstring($result);
    expect($width)->toBe(Avatar::SIZE)
        ->and($height)->toBe(Avatar::SIZE);
});

test('it returns null for bytes that are not a decodable image', function () {
    expect(Avatar::fromImage('not an image'))->toBeNull();
});
