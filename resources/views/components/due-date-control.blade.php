@props([
    'date',
    'model' => 'dueDate',
    'canEdit' => false,
    'size' => 'sm',
])

@if ($canEdit)
    @if ($date && $date->isPast() && ! $date->isToday())
        <flux:tooltip :content="__('Overdue')">
            <flux:icon.exclamation-triangle variant="micro" class="text-red-500" data-test="due-date-overdue" />
        </flux:tooltip>
    @endif

    <flux:date-picker wire:model.live="{{ $model }}" selectable-header with-today data-test="due-date-control">
        <x-slot:trigger>
            <flux:date-picker.button
                size="xs"
                clearable
                :placeholder="__('No due date')"
                data-test="due-date-trigger"
            />
        </x-slot:trigger>
    </flux:date-picker>
@elseif ($date)
    <x-due-date-badge :date="$date" :size="$size" />
@else
    <flux:text size="sm" class="text-zinc-400">{{ __('No due date') }}</flux:text>
@endif
