<?php

/** @noinspection HtmlUnknownTarget */

use Illuminate\Support\Facades\Blade;

it('renders allowed HTML and keeps inline image links', function () {
    $html = Blade::render('<x-rich-text :content="$content" />', [
        'content' => '<p>Hi <strong>there</strong></p>'
            .'<a href="/ABC/attachments/1/view" target="_blank"><img src="/ABC/attachments/1/thumbnail" alt="pic"></a>',
    ]);

    expect($html)
        ->toContain('<strong>there</strong>')
        ->toContain('<img')
        ->toContain('src="/ABC/attachments/1/thumbnail"')
        ->toContain('href="/ABC/attachments/1/view"')
        ->toContain('target="_blank"');
});

it('strips scripts and event handler attributes', function () {
    $html = Blade::render('<x-rich-text :content="$content" />', [
        'content' => '<p onclick="steal()">hi</p><script>alert(1)</script>',
    ]);

    expect($html)
        ->toContain('hi')
        ->not->toContain('<script')
        ->not->toContain('onclick');
});
