@props(['progress', 'barClass' => 'w-20'])

@if ($progress->hasTasks())
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
        <flux:progress :value="$progress->percent()" color="green" :class="$barClass" />
        <flux:text size="xs" class="shrink-0 whitespace-nowrap text-zinc-400">
            {{ $progress->done }} / {{ $progress->total }} {{ __('done') }}
        </flux:text>
    </div>
@endif
