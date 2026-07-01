@props([
    'status',
    'model' => 'status',
    'canEdit' => false,
    'size' => 'sm',
])
@php
/** @var \App\Enums\Status $status */
@endphp

@if ($canEdit)
    <x-badge-dropdown :model="$model" test="status-control">
        <x-slot:trigger>
            <flux:badge
                as="button"
                :size="$size"
                :color="$status->color()"
                :icon="$status->icon()"
                icon:trailing="chevron-down"
                class="cursor-pointer"
                data-test="status-control-current"
            >
                {{ $status->label() }}
            </flux:badge>
        </x-slot:trigger>

        @foreach (\App\Enums\Status::columns() as $option)
            <flux:menu.radio :value="$option->value" :icon="$option->icon()" data-test="status-option-{{ $option->value }}">
                {{ $option->label() }}
            </flux:menu.radio>
        @endforeach
    </x-badge-dropdown>
@else
    <x-status-badge :status="$status" :size="$size" data-test="status-control" />
@endif
