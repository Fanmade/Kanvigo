@props([
    'name',            // the wire property holding the chosen icon
    'selected' => null, // its current value
    'test',            // data-test prefix, e.g. "edit-tag" → "edit-tag-icon-picker"
    'clear' => null,    // optional clear method; defaults to setting the property null
])

{{--
    The curated icon picker shared by every place a tag or task type is given an
    icon (tag rail, create-task modal, project tags, project task types). A "no
    icon" button followed by the curated {@see \App\Models\TaskType::ICONS} set.
--}}
<div class="flex flex-col gap-1.5">
    <flux:label>{{ __('Icon') }}</flux:label>
    <div class="flex max-h-44 flex-wrap gap-2 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-white/10" data-test="{{ $test }}-icon-picker">
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
        @foreach (\App\Models\TaskType::ICONS as $iconName)
            <button
                type="button"
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
    </div>
    <flux:error :name="$name" />
</div>
