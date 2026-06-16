<div class="flex h-full flex-col gap-4">
    <flux:heading size="xl">{{ __('Board') }}</flux:heading>

    <x-kanban-board :columns="$this->columns" />
</div>
