@props(['content' => ''])

{{-- Renders a stored HTML description, sanitized against an allow-list. --}}
<div {{ $attributes->merge(['class' => 'prose prose-zinc max-w-none dark:prose-invert']) }}>
    {!! app(\App\Support\RichTextSanitizer::class)->sanitize($content) !!}
</div>
