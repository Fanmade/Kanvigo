<div class="flex flex-col gap-6" data-test="notes-page">
    <div class="flex items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Notes') }}</flux:heading>
        <flux:button size="sm" icon="plus" wire:click="$dispatch('open-create-note')" data-test="new-note">{{ __('New note') }}</flux:button>
    </div>

    <flux:card class="flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700" data-test="notes-list">
        @forelse ($this->notes as $note)
            <x-note-row :note="$note" :reorderable="true" wire:key="note-{{ $note->id }}" />
        @empty
            <flux:text size="sm" class="px-4 py-10 text-center text-zinc-400" data-test="notes-empty">{{ __('No notes yet. Capture an idea to get started.') }}</flux:text>
        @endforelse
    </flux:card>
</div>
