<div class="flex h-full flex-col gap-4">
    <div class="flex flex-col gap-1">
        <a href="{{ route('project.show', $this->project) }}" wire:navigate class="flex w-fit items-center gap-1 text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
            <flux:icon.chevron-left variant="micro" />
            {{ $this->project->short_name }} · {{ $this->project->title }}
        </a>

        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('Board') }}</flux:heading>

            <div class="flex items-center gap-2">
                <flux:switch wire:model.live="showArchived" :label="__('Show archived')" align="left" data-test="show-archived" />
                <flux:select wire:model.live="priorityFilter" size="sm" class="max-w-44" data-test="priority-filter">
                    <flux:select.option value="">{{ __('All priorities') }}</flux:select.option>
                    @foreach (\App\Enums\Priority::ordered() as $priority)
                        <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button variant="primary" icon="plus" wire:click="openTaskModal" data-test="new-task">{{ __('New task') }}</flux:button>
            </div>
        </div>
    </div>

    <x-kanban-board :columns="$this->columns" :blocked-ids="$this->blockedTaskIds" />

    {{-- Create task --}}
    <flux:modal wire:model="showTaskModal" class="md:w-96">
        <form wire:submit="createTask" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('New task') }}</flux:heading>

            <flux:input wire:model="taskTitle" :label="__('Title')" data-test="task-title" />
            <flux:textarea wire:model="taskDescription" :label="__('Description')" rows="3" />

            <flux:select wire:model="taskPriority" :label="__('Priority')" data-test="task-priority">
                @foreach (\App\Enums\Priority::ordered() as $priority)
                    <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="taskDueDate" :label="__('Due date')" :description="__('Optional')" />

            <flux:select wire:model="taskStatus" :label="__('Status')">
                @foreach (\App\Enums\Status::columns() as $status)
                    <flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
