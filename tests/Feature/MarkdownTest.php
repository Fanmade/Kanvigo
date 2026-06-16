<?php

use Illuminate\Support\Facades\Blade;

it('opens embedded image links in a new tab', function () {
    $html = Blade::render('<x-markdown :content="$content" />', [
        'content' => '[![pic](/attachments/1/thumbnail)](/attachments/1/view)',
    ]);

    expect($html)
        ->toContain('<img')
        ->toContain('href="/attachments/1/view"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"');
});

it('leaves plain text links without a new-tab target', function () {
    $html = Blade::render('<x-markdown :content="$content" />', [
        'content' => '[docs](/handbook)',
    ]);

    expect($html)
        ->toContain('href="/handbook"')
        ->not->toContain('target="_blank"');
});
