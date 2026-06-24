@props(['type', 'size' => 'sm'])

@if ($type)
    <flux:badge :size="$size" :color="$type->color" :icon="$type->icon">
        {{ $type->name }}
    </flux:badge>
@endif
