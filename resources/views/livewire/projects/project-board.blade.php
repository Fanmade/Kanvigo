<div class="flex h-full flex-col gap-4">
    <div class="flex flex-col gap-1">
        <a href="{{ route('project.show', $this->project) }}" wire:navigate class="flex w-fit items-center gap-1 text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
            <flux:icon.chevron-left variant="micro" />
            {{ $this->project->short_name }} · {{ $this->project->title }}
        </a>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="xl">{{ __('Board') }}</flux:heading>

            <div class="flex items-center gap-2">
                <flux:dropdown align="end">
                    <flux:button icon="funnel" data-test="board-filters">
                        {{ __('Filters') }}
                        @if ($this->activeFilterCount > 0)
                            <flux:badge size="sm" color="blue" class="ms-1">{{ $this->activeFilterCount }}</flux:badge>
                        @endif
                    </flux:button>

                    <flux:popover class="flex w-64 flex-col gap-3">
                        <flux:switch wire:model.live="showArchived" :label="__('Show archived')" align="left" data-test="show-archived" />
                        <flux:select variant="listbox" wire:model.live="priorityFilter" size="sm" :label="__('Priority')" data-test="priority-filter">
                            <flux:select.option value="">{{ __('All priorities') }}</flux:select.option>
                            @foreach (\App\Enums\Priority::descending() as $priority)
                                <flux:select.option :value="$priority->value">
                                    <x-priority-badge :priority="$priority" />
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        @if (count($this->taskTypes) > 0)
                            <flux:select wire:model.live="typeFilter" size="sm" :label="__('Type')" data-test="type-filter">
                                <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                                @foreach ($this->taskTypes as $type)
                                    <flux:select.option :value="$type->id">{{ $type->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                        @if ($this->members->isNotEmpty())
                            <flux:select variant="listbox" wire:model.live="assigneeFilter" size="sm" :label="__('Assignee')" data-test="assignee-filter">
                                <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                                @foreach ($this->members as $member)
                                    <flux:select.option :value="$member->id">
                                        {{ $member->getKey() === auth()->id() ? __('Assigned to me') : $member->name }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                    </flux:popover>
                </flux:dropdown>

                <flux:dropdown align="end">
                    <flux:button icon="adjustments-horizontal" :aria-label="__('Display options')" data-test="board-display" />

                    <flux:popover class="flex w-64 flex-col gap-3">
                        <x-live-updates-toggle />
                    </flux:popover>
                </flux:dropdown>

                <flux:button variant="primary" icon="plus" wire:click="$dispatch('open-create-task', { projectId: {{ $this->project->id }} })" data-test="new-task">{{ __('New task') }}</flux:button>
            </div>
        </div>
    </div>

    <x-board-auto-refresh :interval-ms="$this->livePollIntervalMs()">
        <x-kanban-board :columns="$this->columns" :blocked-ids="$this->blockedTaskIds" />
    </x-board-auto-refresh>

    @include('partials.tasks.parent-close-modal')
</div>
