@props([
    'name',            // the wire property holding the chosen icon
    'selected' => null, // its current value
    'test',            // data-test prefix, e.g. "edit-tag" → "edit-tag-icon-picker"
    'clear' => null,    // optional clear method; defaults to setting the property null
])

{{--
    The icon picker shared by every place a tag or task type is given an icon
    (tag rail, create-task modal, project tags, project task types). A search
    field over every icon Flux can render, then a "no icon" button followed by
    the matching icons — a capped compact slice by default, or, after "show
    all", every match as a labelled grid for browsing when the name is unknown.
    The using component must have the
    {@see \App\Concerns\InteractsWithIconPicker} concern, which holds the query
    and exposes the capped {@see \App\Support\IconCatalog::search()} result.
--}}
@php($showAll = $this->showAllIcons)
<div class="flex flex-col gap-1.5">
    <div class="flex items-center justify-between gap-2">
        <flux:label>{{ __('Icon') }}</flux:label>
        @if ($showAll || $this->iconMatchCount > count($this->iconOptions))
            <flux:button
                type="button"
                size="xs"
                variant="ghost"
                wire:click="toggleAllIcons"
                :icon="$showAll ? 'arrows-pointing-in' : 'squares-2x2'"
                data-test="{{ $test }}-icon-show-all"
            >
                {{ $showAll ? __('Show less') : __('Show all (:count)', ['count' => $this->iconMatchCount]) }}
            </flux:button>
        @endif
    </div>
    <flux:input
        wire:model.live.debounce.300ms="iconQuery"
        icon="magnifying-glass"
        size="sm"
        :placeholder="__('Search icons')"
        :aria-label="__('Search icons')"
        data-test="{{ $test }}-icon-search"
    />
    <div
        @class([
            'overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-white/10',
            'flex max-h-44 flex-wrap gap-2' => ! $showAll,
            'grid max-h-[60vh] grid-cols-[repeat(auto-fill,minmax(6.5rem,1fr))] gap-1.5' => $showAll,
        ])
        data-test="{{ $test }}-icon-picker"
        {{-- Marker the surrounding modal widens on via has-[[data-icon-overview]]: --}}
        @if ($showAll) data-icon-overview="1" @endif
    >
        <button
            type="button"
            @if ($clear) wire:click="{{ $clear }}" @else wire:click="$set('{{ $name }}', null)" @endif
            @class([
                'flex cursor-pointer items-center justify-center rounded-lg border',
                'size-8' => ! $showAll,
                'h-16 flex-col gap-1 px-0.5' => $showAll,
                'border-zinc-900 dark:border-white' => $selected === null,
                'border-zinc-200 dark:border-white/10' => $selected !== null,
            ])
            aria-label="{{ __('No icon') }}"
            data-test="{{ $test }}-icon-none"
        >
            <flux:icon icon="no-symbol" :variant="$showAll ? 'mini' : 'micro'" class="text-zinc-400" />
            @if ($showAll)
                <span class="line-clamp-2 w-full text-center text-[10px] leading-tight [overflow-wrap:anywhere] text-zinc-500 dark:text-zinc-400">{{ __('No icon') }}</span>
            @endif
        </button>
        {{--
            The chosen icon leads the list even when the query filters it out, so
            it stays visibly selected. Validated first: an invalid value (a stale
            icon, a tampered property) must never reach <flux:icon>, which throws
            on an unknown component.
        --}}
        @php($pinned = \App\Support\IconCatalog::validOrNull($selected))
        @php($options = $pinned === null || in_array($pinned, $this->iconOptions, true) ? $this->iconOptions : [$pinned, ...$this->iconOptions])
        @foreach ($options as $iconName)
            <button
                type="button"
                wire:key="{{ $test }}-icon-{{ $iconName }}"
                wire:click="$set('{{ $name }}', '{{ $iconName }}')"
                @class([
                    'flex cursor-pointer items-center justify-center rounded-lg border',
                    'size-8' => ! $showAll,
                    'h-16 min-w-0 flex-col gap-1 px-0.5 hover:bg-zinc-50 dark:hover:bg-white/5' => $showAll,
                    'border-zinc-900 dark:border-white' => $selected === $iconName,
                    'border-zinc-200 dark:border-white/10' => $selected !== $iconName,
                ])
                aria-label="{{ $iconName }}"
                title="{{ $iconName }}"
                data-test="{{ $test }}-icon-{{ $iconName }}"
            >
                <flux:icon :icon="$iconName" :variant="$showAll ? 'mini' : 'micro'" class="text-zinc-600 dark:text-zinc-300" />
                @if ($showAll)
                    <span class="line-clamp-2 w-full text-center text-[10px] leading-tight [overflow-wrap:anywhere] text-zinc-500 dark:text-zinc-400">{{ $iconName }}</span>
                @endif
            </button>
        @endforeach
        @if ($this->iconOptions === [])
            <flux:text size="sm" @class(['p-1', 'col-span-full' => $showAll]) data-test="{{ $test }}-icon-empty">
                {{ __('No icons match your search.') }}
            </flux:text>
        @endif
    </div>
    <flux:error :name="$name" />
</div>
