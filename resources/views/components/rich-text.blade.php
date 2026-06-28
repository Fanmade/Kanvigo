@props(['content' => ''])

{{--
    Renders a stored HTML description, sanitized against an allow-list. @mention
    spans are first rewritten into links to the mentioned user's profile, so they
    are clickable wherever a description or comment is shown.
--}}
<div {{ $attributes->merge(['class' => 'prose prose-zinc max-w-none dark:prose-invert']) }}>
    {!! app(\App\Support\RichTextSanitizer::class)->sanitize(\App\Support\MentionLinker::link($content)) !!}
</div>
