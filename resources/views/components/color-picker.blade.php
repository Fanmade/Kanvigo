@props(['palette', 'selected' => null, 'name', 'test'])

{{--
    A row of color swatches for picking a tag/task-type color. Each swatch sets the
    given Livewire property ($name) via wire:click and rings the currently selected
    one. Used by the tag and task-type editors and the create-task tag form.

    - palette:  the list of color names to offer (e.g. Tag::PALETTE).
    - selected: the currently chosen color, ringed in the row.
    - name:     the Livewire property the swatch sets (e.g. "newTagColor").
    - test:     the data-test prefix; the row is "{test}-color-picker" and each
                swatch "{test}-color-{color}".
--}}
<div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Color') }}" data-test="{{ $test }}-color-picker">
    @foreach ($palette as $paletteColor)
        <button
            type="button"
            wire:click="$set('{{ $name }}', '{{ $paletteColor }}')"
            @class([
                'flex size-7 cursor-pointer items-center justify-center rounded-full ring-2 ring-offset-2 ring-offset-white dark:ring-offset-zinc-800',
                'ring-zinc-900 dark:ring-white' => $selected === $paletteColor,
                'ring-transparent' => $selected !== $paletteColor,
            ])
            aria-label="{{ $paletteColor }}"
            aria-pressed="{{ $selected === $paletteColor ? 'true' : 'false' }}"
            data-test="{{ $test }}-color-{{ $paletteColor }}"
        >
            <x-tag-dot :color="$paletteColor" class="size-5" />
        </button>
    @endforeach
</div>
