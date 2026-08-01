@props(['status', 'size' => 'sm'])
@php
    /** @var \App\Enums\Status|null $status */
@endphp

{{-- A read-only status pill, mirroring x-priority-badge / x-task-type-badge. --}}
@if ($status)
    <flux:badge :size="$size" :color="$status->color()" :icon="$status->icon()" {{ $attributes }}>
        {{ $status->label() }}
    </flux:badge>
@endif
