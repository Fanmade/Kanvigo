@props(['tag'])

{{-- A single tag rendered as a neutral pill with a dot in the tag's color. --}}
<flux:badge size="sm" color="zinc" variant="pill" {{ $attributes }}>
    <x-tag-dot :color="$tag->color" class="me-1.5" />{{ $tag->name }}
</flux:badge>
