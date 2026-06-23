<div class="flex h-full flex-col gap-4">
    <div class="flex flex-col gap-1">
        <a href="{{ route('project.show', $this->project) }}" wire:navigate class="flex w-fit items-center gap-1 text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
            <flux:icon.chevron-left variant="micro" />
            {{ $this->project->short_name }} · {{ $this->project->title }}
        </a>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="xl">{{ __('Board') }}</flux:heading>

            <div class="flex flex-wrap items-center gap-2">
                <x-live-updates-toggle />
                <flux:switch wire:model.live="showArchived" :label="__('Show archived')" align="left" data-test="show-archived" />
                <flux:select wire:model.live="priorityFilter" size="sm" class="max-w-44" data-test="priority-filter">
                    <flux:select.option value="">{{ __('All priorities') }}</flux:select.option>
                    @foreach (\App\Enums\Priority::ordered() as $priority)
                        <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button variant="primary" icon="plus" wire:click="$dispatch('open-create-task', { projectId: {{ $this->project->id }} })" data-test="new-task">{{ __('New task') }}</flux:button>
            </div>
        </div>
    </div>

    <x-board-auto-refresh :interval-ms="$this->livePollIntervalMs()">
        <x-kanban-board :columns="$this->columns" :blocked-ids="$this->blockedTaskIds" />
    </x-board-auto-refresh>

    @include('partials.tasks.parent-close-modal')
</div>
