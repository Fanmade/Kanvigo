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
    the matching icons. The using component must have the
    {@see \App\Concerns\InteractsWithIconPicker} concern, which holds the query
    and exposes the capped {@see \App\Support\IconCatalog::search()} result.
--}}
<div class="flex flex-col gap-1.5">
    <flux:label>{{ __('Icon') }}</flux:label>
    <flux:input
        wire:model.live.debounce.300ms="iconQuery"
        icon="magnifying-glass"
        size="sm"
        :placeholder="__('Search icons')"
        :aria-label="__('Search icons')"
        data-test="{{ $test }}-icon-search"
    />
    <div
        class="flex max-h-44 flex-wrap gap-2 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-white/10"
        data-test="{{ $test }}-icon-picker"
    >
        <button
            type="button"
            @if ($clear) wire:click="{{ $clear }}" @else wire:click="$set('{{ $name }}', null)" @endif
            @class([
                'flex size-8 cursor-pointer items-center justify-center rounded-lg border',
                'border-zinc-900 dark:border-white' => $selected === null,
                'border-zinc-200 dark:border-white/10' => $selected !== null,
            ])
            aria-label="{{ __('No icon') }}"
            data-test="{{ $test }}-icon-none"
        >
            <flux:icon icon="no-symbol" variant="micro" class="text-zinc-400" />
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
                    'flex size-8 cursor-pointer items-center justify-center rounded-lg border',
                    'border-zinc-900 dark:border-white' => $selected === $iconName,
                    'border-zinc-200 dark:border-white/10' => $selected !== $iconName,
                ])
                aria-label="{{ $iconName }}"
                data-test="{{ $test }}-icon-{{ $iconName }}"
            >
                <flux:icon :icon="$iconName" variant="micro" class="text-zinc-600 dark:text-zinc-300" />
            </button>
        @endforeach
        @if ($this->iconOptions === [])
            <flux:text size="sm" class="p-1" data-test="{{ $test }}-icon-empty">
                {{ __('No icons match your search.') }}
            </flux:text>
        @endif
    </div>
    <flux:error :name="$name" />
</div>
