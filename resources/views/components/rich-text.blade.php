@props(['content' => '', 'shortName' => null, 'variables' => true])

{{--
    Renders a stored HTML description, sanitized against an allow-list. @mention
    spans are first rewritten into links to the mentioned user's profile, so they
    are clickable wherever a description or comment is shown. Pass the surrounding
    project's `:short-name` so each mention link carries it for the hovercard.

    Finally, `[name]` usages resolve to what the project's variable currently
    stands for — after sanitisation, so the inserted markup is not subject to the
    allow-list. Pass `:variables="false"` for content that is not part of the
    project's namespace (a personal note shown on a project page).
--}}
<div {{ $attributes->merge(['class' => 'prose prose-zinc max-w-none overflow-x-auto dark:prose-invert']) }}>
    {!!
        app(\App\Support\VariableSubstitutor::class)->substitute(
            app(\App\Support\RichTextSanitizer::class)->sanitize(\App\Support\MentionLinker::link($content, $shortName)),
            $variables ? $shortName : null,
        )
    !!}
</div>
