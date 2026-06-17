@props(['priority', 'size' => 'sm'])

@if ($priority)
    <flux:badge :size="$size" :color="$priority->color()" :icon="$priority->icon()">
        {{ $priority->label() }}
    </flux:badge>
@endif
