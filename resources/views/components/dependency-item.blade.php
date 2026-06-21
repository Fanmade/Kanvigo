@props([
    'item',
    'linkId',
    'canRemove' => false,
])

@php
    $url = route('task.show', [
        'short_name' => $item->project->short_name,
        'task_number' => $item->task_number,
    ]);
    $complete = $item->isComplete();
@endphp

<div class="flex items-center justify-between gap-2" data-test="dependency-{{ $item->reference }}">
    <a href="{{ $url }}" wire:navigate class="flex min-w-0 items-center gap-2">
        <flux:badge
            size="sm"
            :color="$complete ? 'green' : 'amber'"
            :icon="$complete ? 'check-circle' : 'clock'"
        >
            {{ $item->reference }}
        </flux:badge>
        <span class="truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $item->title }}</span>
    </a>

    @if ($canRemove)
        <flux:button
            size="xs"
            variant="subtle"
            icon="x-mark"
            wire:click="removeDependency({{ $linkId }})"
            :aria-label="__('Remove dependency')"
            :data-test="'remove-dependency-'.$linkId"
        />
    @endif
</div>
