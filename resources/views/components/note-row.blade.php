@props(['note', 'reorderable' => false])

{{-- A single note row: title (opens the edit dialog), status badges and an
     actions menu. Shared by the dashboard panel and the Notes page; the owner
     actions (edit/convert/visibility/delete) are handled by the host component
     via the ManagesNotes concern and the globally-mounted dialogs. --}}
<div {{ $attributes->class('flex items-start justify-between gap-3 px-4 py-3') }} data-test="note-{{ $note->id }}">
    <div class="flex min-w-0 flex-1 flex-col gap-1">
        <button
            type="button"
            wire:click="$dispatch('open-create-note', { noteId: {{ $note->id }} })"
            class="cursor-pointer truncate text-start text-sm font-medium hover:underline"
            data-test="edit-note-{{ $note->id }}"
        >{{ $note->title }}</button>

        <div class="flex flex-wrap items-center gap-1.5">
            @if ($note->project)
                <flux:badge size="sm" color="indigo" variant="pill">{{ $note->project->short_name }}</flux:badge>
            @endif

            @if ($note->is_public)
                <flux:badge size="sm" color="green" icon="globe-alt">{{ __('Public') }}</flux:badge>
            @else
                <flux:badge size="sm" color="zinc" icon="lock-closed">{{ __('Private') }}</flux:badge>
            @endif

            @if ($note->convertedTask)
                <a
                    href="{{ route('task.show', ['short_name' => $note->convertedTask->project->short_name, 'task_number' => $note->convertedTask->task_number]) }}"
                    wire:navigate
                >
                    <flux:badge size="sm" color="purple" icon="arrow-right-circle">{{ __('Converted → :ref', ['ref' => $note->convertedTask->reference]) }}</flux:badge>
                </a>
            @endif
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-0.5">
        @if ($reorderable)
            <flux:button
                size="xs"
                variant="ghost"
                icon="chevron-up"
                class="text-zinc-400!"
                wire:click="moveNoteUp({{ $note->id }})"
                :aria-label="__('Move up')"
                data-test="move-note-up-{{ $note->id }}"
            />
            <flux:button
                size="xs"
                variant="ghost"
                icon="chevron-down"
                class="text-zinc-400!"
                wire:click="moveNoteDown({{ $note->id }})"
                :aria-label="__('Move down')"
                data-test="move-note-down-{{ $note->id }}"
            />
        @endif

        <flux:button
            size="xs"
            variant="ghost"
            icon="star"
            :class="$note->is_pinned ? 'text-amber-500!' : 'text-zinc-400!'"
            wire:click="togglePin({{ $note->id }})"
            :aria-label="$note->is_pinned ? __('Unpin note') : __('Pin note')"
            data-test="toggle-pin-{{ $note->id }}"
        />

        <flux:dropdown align="end">
            <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Note actions')" data-test="note-actions-{{ $note->id }}" />
        <flux:menu>
            <flux:menu.item icon="pencil-square" wire:click="$dispatch('open-create-note', { noteId: {{ $note->id }} })">{{ __('Edit') }}</flux:menu.item>
            @unless ($note->convertedTask)
                <flux:menu.item icon="arrow-right-circle" wire:click="$dispatch('open-create-task', { fromNoteId: {{ $note->id }} })" data-test="convert-note-{{ $note->id }}">{{ __('Convert to task') }}</flux:menu.item>
            @endunless
            @if ($note->project)
                <flux:menu.item
                    :icon="$note->is_public ? 'lock-closed' : 'globe-alt'"
                    wire:click="toggleNoteVisibility({{ $note->id }})"
                    data-test="toggle-note-visibility-{{ $note->id }}"
                >
                    {{ $note->is_public ? __('Make private') : __('Make public') }}
                </flux:menu.item>
            @endif
            <flux:menu.separator />
            <flux:menu.item
                icon="trash"
                variant="danger"
                wire:click="deleteNote({{ $note->id }})"
                wire:confirm="{{ __('Delete this note?') }}"
                data-test="delete-note-{{ $note->id }}"
            >{{ __('Delete') }}</flux:menu.item>
        </flux:menu>
        </flux:dropdown>
    </div>
</div>
