@props(['content' => ''])

@php
    $html = \Illuminate\Support\Str::markdown($content ?? '', [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]);

    // Open embedded image links (a thumbnail linking to its full-size image) in
    // a new tab instead of navigating away from the description.
    $html = preg_replace(
        '/<a href="([^"]*)">(\s*<img\b)/',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$2',
        $html,
    );
@endphp

<div {{ $attributes->merge(['class' => 'prose prose-zinc max-w-none dark:prose-invert']) }}>
    {!! $html !!}
</div>
