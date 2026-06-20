@props(['label'])

<div class="flex items-center justify-between gap-3">
    <flux:text size="sm" class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
    <div class="flex min-w-0 items-center justify-end gap-1">
        {{ $slot }}
    </div>
</div>
