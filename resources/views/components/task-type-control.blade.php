@props([
    'type',
    'types',
    'model' => 'typeId',
    'canEdit' => false,
    'size' => 'sm',
])

@if ($canEdit)
    <flux:dropdown align="end" data-test="task-type-control">
        @if ($type)
            <flux:badge
                as="button"
                :size="$size"
                :color="$type->color"
                :icon="$type->icon"
                icon:trailing="chevron-down"
                class="cursor-pointer"
            >
                {{ $type->name }}
            </flux:badge>
        @else
            <flux:badge as="button" :size="$size" color="zinc" icon:trailing="chevron-down" class="cursor-pointer">
                {{ __('No type') }}
            </flux:badge>
        @endif

        <flux:menu>
            <flux:menu.radio.group wire:model.live="{{ $model }}">
                <flux:menu.radio value="" data-test="task-type-option-none">{{ __('No type') }}</flux:menu.radio>
                @foreach ($types as $option)
                    <flux:menu.radio :value="$option->id" :icon="$option->icon" data-test="task-type-option-{{ $option->id }}">
                        {{ $option->name }}
                    </flux:menu.radio>
                @endforeach
            </flux:menu.radio.group>
        </flux:menu>
    </flux:dropdown>
@elseif ($type)
    <x-task-type-badge :type="$type" :size="$size" />
@else
    <flux:text size="sm" class="text-zinc-400">{{ __('No type') }}</flux:text>
@endif
