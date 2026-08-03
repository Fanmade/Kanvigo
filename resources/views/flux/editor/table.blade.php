{{--
    Insert-table toolbar item for the Flux editor, referenced by name ("table")
    in a toolbar configuration. Opens a grid-size picker: hovering a cell
    selects the table dimensions up to it, clicking inserts the table at the
    cursor. The picker logic lives in the `editorTablePicker` Alpine component
    and the Tiptap table extensions are registered in resources/js/app.js.
--}}
<flux:dropdown class="contents">
    <flux:tooltip content="{{ __('Insert table') }}" class="contents">
        <flux:editor.button data-test="editor-table-button">
            <flux:icon.table-cells variant="outline" class="size-5!" />
        </flux:editor.button>
    </flux:tooltip>

    <flux:popover
        x-data="editorTablePicker"
        x-on:toggle="$event.newState === 'closed' && reset()"
        class="p-2"
    >
        <div class="grid w-max grid-cols-8 gap-1" x-on:mouseleave="reset()">
            <template x-for="cell in maxRows * maxCols" :key="cell">
                <button
                    type="button"
                    class="size-4 rounded-xs border"
                    :class="isSelected(cell)
                        ? 'border-accent bg-accent/20'
                        : 'border-zinc-300 hover:border-zinc-400 dark:border-zinc-500 dark:hover:border-zinc-400'"
                    x-on:mouseenter="highlight(cell)"
                    x-on:focus="highlight(cell)"
                    x-on:click="insert()"
                    :aria-label="rowOf(cell) + ' × ' + colOf(cell)"
                    :data-test="'table-size-' + rowOf(cell) + 'x' + colOf(cell)"
                ></button>
            </template>
        </div>

        <div class="pt-1.5 text-center text-xs text-zinc-500 dark:text-zinc-400">
            <span x-show="rows === 0">{{ __('Insert table') }}</span>
            <span x-show="rows > 0" x-text="rows + ' × ' + cols"></span>
        </div>
    </flux:popover>
</flux:dropdown>
