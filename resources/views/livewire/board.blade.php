<div class="flex h-full flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Board') }}</flux:heading>

        <flux:switch wire:model.live="showArchived" :label="__('Show archived')" align="left" data-test="show-archived" />
    </div>

    <x-kanban-board :columns="$this->columns" :blocked-ids="$this->blockedTaskIds" />

    @include('partials.tasks.parent-close-modal')
</div>
