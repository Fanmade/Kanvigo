@props([
    'priority',
    'model' => 'priority',
    'canEdit' => false,
    'size' => 'sm',
])

@if ($canEdit)
    <x-badge-dropdown :model="$model" test="priority-control">
        <x-slot:trigger>
            <flux:badge
                as="button"
                :size="$size"
                :color="$priority->color()"
                :icon="$priority->icon()"
                icon:trailing="chevron-down"
                class="cursor-pointer"
            >
                {{ $priority->label() }}
            </flux:badge>
        </x-slot:trigger>

        @foreach (\App\Enums\Priority::descending() as $option)
            <flux:menu.radio
                :value="$option->value"
                :icon="$option->icon()"
                data-test="priority-option-{{ $option->value }}"
            >
                {{ $option->label() }}
            </flux:menu.radio>
        @endforeach
    </x-badge-dropdown>
@else
    <x-priority-badge :priority="$priority" :size="$size" />
@endif
