@props(['color' => 'zinc', 'icon' => null])

{{--
    A tag's leading marker: its Heroicon when one is set, otherwise a small dot —
    both tinted with the tag's color. The color → class maps live on the Tag model
    (Tag::dotBackgroundClass/dotForegroundClass) so the palette and its classes
    stay in one place; the literals there are written out in full so Tailwind's
    JIT keeps them.
--}}
@php
    $background = \App\Models\Tag::dotBackgroundClass($color);
    $foreground = \App\Models\Tag::dotForegroundClass($color);
@endphp

@if ($icon)
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 {$foreground}"]) }}>
        <flux:icon :icon="$icon" variant="micro" class="size-3.5" />
    </span>
@else
    <span {{ $attributes->merge(['class' => "inline-block size-2 shrink-0 rounded-full {$background}"]) }}></span>
@endif
