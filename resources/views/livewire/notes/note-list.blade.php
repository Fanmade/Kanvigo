<div class="flex flex-col gap-6" data-test="notes-page">
    <div class="flex items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Notes') }}</flux:heading>
        <flux:button size="sm" icon="plus" wire:click="$dispatch('open-create-note')" data-test="new-note">{{ __('New note') }}</flux:button>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search notes…')"
            class="sm:max-w-xs"
            clearable
            data-test="note-search"
        />

        <flux:select wire:model.live="projectFilter" class="sm:max-w-xs" data-test="note-project-filter">
            <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
            <flux:select.option value="none">{{ __('Without a project') }}</flux:select.option>
            @foreach ($this->filterProjects as $project)
                <flux:select.option value="{{ $project->id }}">{{ $project->short_name }} · {{ $project->title }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card class="flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700" data-test="notes-list">
        @forelse ($this->notes as $note)
            <x-note-row :note="$note" :reorderable="! $this->isFiltering" wire:key="note-{{ $note->id }}" />
        @empty
            @if ($this->isFiltering)
                <flux:text size="sm" class="px-4 py-10 text-center text-zinc-400" data-test="notes-empty">{{ __('No notes match your search.') }}</flux:text>
            @else
                <flux:text size="sm" class="px-4 py-10 text-center text-zinc-400" data-test="notes-empty">{{ __('No notes yet. Capture an idea to get started.') }}</flux:text>
            @endif
        @endforelse
    </flux:card>
</div>
