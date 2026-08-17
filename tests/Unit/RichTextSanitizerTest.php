<?php

use App\Support\RichTextSanitizer;

/**
 * The editor and the MCP/API write path share one allow-list, so what the
 * sanitizer keeps is what a table written by an agent looks like when it is
 * read back.
 */
it('keeps table markup and its spans', function () {
    $html = (new RichTextSanitizer)->sanitize(
        '<table><thead><tr><th colspan="2">Head</th></tr></thead>'
        .'<tbody><tr><td rowspan="2"><p>Cell</p></td><td>Other</td></tr></tbody></table>'
    );

    expect($html)
        ->toContain('<th colspan="2">Head</th>')
        ->toContain('<td rowspan="2">')
        ->toContain('<p>Cell</p>');
});

it('still strips scripts from around a table', function () {
    $html = (new RichTextSanitizer)->sanitize(
        '<table><tbody><tr><td onclick="alert(1)">Cell</td></tr></tbody></table><script>alert(1)</script>'
    );

    expect($html)
        ->toContain('<td>Cell</td>')
        ->not->toContain('<script')
        ->not->toContain('onclick');
});
