{{--
    Table toolbar item for the Flux editor, referenced by name ("table") in a
    toolbar configuration. The popover is dual-mode, decided each time it opens
    (data-table-mode): outside a table it shows a grid-size picker (hover a cell
    to choose the dimensions, click to insert), inside a table it shows the edit
    menu (add/delete row and column, toggle header, delete table).

    Deliberately no Alpine here: Flux's editor restructures the toolbar DOM when
    it mounts, which races Alpine's init and can leave per-element bindings dead.
    All behavior is driven by delegated document-level listeners in
    resources/js/app.js (which also registers the Tiptap table extensions),
    keyed off the data-table-* attributes; the panels toggle via CSS on the
    popover's data-table-mode.
--}}
@php
    $menuItemClasses = 'rounded-md px-2 py-1.5 text-start text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/10';
@endphp
<flux:dropdown class="contents">
    {{-- Native title tooltip, not flux:tooltip: hiding a Flux tooltip (pointer
         leaving the trigger toward the popover) dismisses the open popover with
         it, making the menu unreachable. --}}
    <flux:editor.button aria-label="{{ __('Table') }}" title="{{ __('Table') }}" data-test="editor-table-button">
        <flux:icon.table-cells variant="outline" class="size-5!" />
    </flux:editor.button>

    {{-- A raw manual popover like the vendor link item (not flux:popover): the
         managed popover light-dismisses when the pointer leaves the trigger,
         which collapses the menu before it can be clicked. tabindex="-1" keeps
         Safari from focusing outside the popover on click (see link.blade.php). --}}
    <div
        popover="manual"
        tabindex="-1"
        class="rounded-lg border border-zinc-200 bg-white p-2 shadow-xs dark:border-zinc-600 dark:bg-zinc-700"
        data-table-popover
        data-table-mode="insert"
    >
        <div class="[[data-table-mode=edit]_&]:hidden">
            <div class="grid w-max grid-cols-8 gap-1" data-table-grid>
                @for ($row = 1; $row <= 6; $row++)
                    @for ($col = 1; $col <= 8; $col++)
                        <button
                            type="button"
                            class="table-pick-cell"
                            data-table-cell
                            data-row="{{ $row }}"
                            data-col="{{ $col }}"
                            aria-label="{{ $row }} × {{ $col }}"
                            data-test="table-size-{{ $row }}x{{ $col }}"
                        ></button>
                    @endfor
                @endfor
            </div>

            <div
                class="pt-1.5 text-center text-xs text-zinc-500 dark:text-zinc-400"
                data-table-size-label
                data-placeholder="{{ __('Insert table') }}"
            >
                {{ __('Insert table') }}
            </div>
        </div>

        <div class="[[data-table-mode=edit]_&]:flex hidden w-44 flex-col" data-test="table-edit-menu">
            <button
                type="button"
                class="{{ $menuItemClasses }}"
                data-table-command="addRowBefore"
                data-test="table-add-row-above"
            >
                {{ __('Add row above') }}
            </button>
            <button
                type="button"
                class="{{ $menuItemClasses }}"
                data-table-command="addRowAfter"
                data-test="table-add-row-below"
            >
                {{ __('Add row below') }}
            </button>
            <button
                type="button"
                class="{{ $menuItemClasses }}"
                data-table-command="addColumnBefore"
                data-test="table-add-column-left"
            >
                {{ __('Add column left') }}
            </button>
            <button
                type="button"
                class="{{ $menuItemClasses }}"
                data-table-command="addColumnAfter"
                data-test="table-add-column-right"
            >
                {{ __('Add column right') }}
            </button>

            <flux:separator variant="subtle" class="my-1" />

            <button
                type="button"
                class="{{ $menuItemClasses }}"
                data-table-command="deleteRow"
                data-test="table-delete-row"
            >
                {{ __('Delete row') }}
            </button>
            <button
                type="button"
                class="{{ $menuItemClasses }}"
                data-table-command="deleteColumn"
                data-test="table-delete-column"
            >
                {{ __('Delete column') }}
            </button>

            <flux:separator variant="subtle" class="my-1" />

            <button
                type="button"
                class="{{ $menuItemClasses }}"
                data-table-command="toggleHeaderRow"
                data-test="table-toggle-header"
            >
                {{ __('Toggle header row') }}
            </button>

            <flux:separator variant="subtle" class="my-1" />

            <button
                type="button"
                class="rounded-md px-2 py-1.5 text-start text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-400/10"
                data-table-command="deleteTable"
                data-test="table-delete"
            >
                {{ __('Delete table') }}
            </button>
        </div>
    </div>
</flux:dropdown>
