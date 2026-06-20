<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('project.show', $this->story->project) }}" wire:navigate class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                {{ $this->story->project->short_name }}
            </a>
            <span class="text-zinc-300">/</span>
            <span class="font-mono text-zinc-400">{{ $this->story->reference }}</span>
        </div>
        <livewire:subscriptions.subscription-toggle :subscribable="$this->story" :wire:key="'sub-story-'.$this->story->id" />
    </div>

    @php($canUpdate = auth()->user()->can('update', $this->story))

    @if ($editing)
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:input wire:model="title" :label="__('Title')" />
            <x-attachments.markdown-editor :label="__('Description')" />
            <x-attachments.upload-button />
            <flux:input type="date" wire:model="dueDate" :label="__('Due date')" :description="__('Optional')" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('editing', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
            {{-- Main column --}}
            <div class="flex min-w-0 flex-1 flex-col gap-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ $this->story->title }}</flux:heading>
                        @if ($this->story->isArchived())
                            <flux:badge size="sm" color="zinc" icon="archive-box" data-test="story-archived-badge">{{ __('Archived') }}</flux:badge>
                        @endif
                    </div>
                    @can('update', $this->story)
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" data-test="story-actions" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit" data-test="edit-story">{{ __('Edit') }}</flux:menu.item>
                                @if ($this->story->isArchived())
                                    <flux:menu.item icon="arrow-up-tray" wire:click="unarchiveStory" data-test="unarchive-story">{{ __('Unarchive') }}</flux:menu.item>
                                @else
                                    <flux:menu.item icon="archive-box" wire:click="archiveStory" data-test="archive-story">{{ __('Archive') }}</flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    @endcan
                </div>

                <div class="flex flex-wrap items-center gap-1">
                    <x-due-date-badge :date="$this->story->due_date" />
                </div>

                <x-attachments.dropzone :enabled="$canUpdate">
                    <flux:card>
                        @if ($this->story->description)
                            <x-expandable-description :content="$this->story->description" />
                        @else
                            <flux:text class="italic text-zinc-400">{{ __('No description yet.') }}</flux:text>
                        @endif
                    </flux:card>
                </x-attachments.dropzone>

                <x-attachments.list :attachments="$this->attachments" />

                <div>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <flux:heading size="sm">{{ __('Tasks') }}</flux:heading>
                        <div class="flex items-center gap-3">
                            <x-story-progress :progress="$this->story->progress()" />
                            @can('update', $this->story)
                                <flux:button size="sm" icon="plus" wire:click="openTaskModal" data-test="new-task">{{ __('New task') }}</flux:button>
                            @endcan
                        </div>
                    </div>
                    <flux:card class="flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700">
                        @forelse ($this->story->tasks as $task)
                            <a
                                href="{{ route('task.show', ['short_name' => $this->story->project->short_name, 'task_number' => $task->task_number]) }}"
                                wire:navigate
                                class="flex items-center justify-between gap-2 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <flux:text size="xs" class="font-mono text-zinc-400">{{ $task->reference }}</flux:text>
                                    <span @class(['text-sm', 'truncate text-zinc-400' => $task->isArchived()])>{{ $task->title }}</span>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    @if ($task->isArchived())
                                        <flux:badge size="sm" color="zinc" icon="archive-box">{{ __('Archived') }}</flux:badge>
                                    @endif
                                    <flux:badge size="sm" :color="$task->status->color()" :icon="$task->status->icon()">{{ $task->status->label() }}</flux:badge>
                                </div>
                            </a>
                        @empty
                            <flux:text size="sm" class="px-4 py-3 text-zinc-400">{{ __('No tasks yet.') }}</flux:text>
                        @endforelse
                    </flux:card>
                </div>

                <livewire:comments.comment-list :commentable="$this->story" :wire:key="'comments-story-'.$this->story->id" />

                <livewire:activity.activity-feed :subject="$this->story" :wire:key="'activity-story-'.$this->story->id" />
            </div>

            {{-- Metadata rail --}}
            <aside class="w-full shrink-0 lg:w-72">
                <flux:card class="flex flex-col gap-4">
                    <x-rail-row :label="__('Priority')">
                        <x-priority-control :priority="$this->story->priority" :can-edit="$canUpdate" />
                    </x-rail-row>

                    <x-rail-row :label="__('Assignees')">
                        @if ($this->story->assignees->isNotEmpty())
                            <flux:avatar.group>
                                @foreach ($this->story->assignees as $assignee)
                                    <x-user-avatar :user="$assignee" circle :tooltip="$assignee->name" />
                                @endforeach
                            </flux:avatar.group>
                        @else
                            <flux:text size="sm" class="text-zinc-400">{{ __('Unassigned') }}</flux:text>
                        @endif

                        @if ($canUpdate)
                            <flux:dropdown align="end" data-test="assignees-control">
                                <flux:button size="xs" variant="subtle" icon="plus" :aria-label="__('Edit assignees')" />
                                <flux:popover class="flex w-64 flex-col gap-2">
                                    <flux:text size="xs" class="font-medium text-zinc-400">{{ __('Assignees') }}</flux:text>
                                    <flux:select variant="listbox" multiple wire:model.live="assigneeIds" :placeholder="__('Assign members')">
                                        @foreach ($this->members as $member)
                                            <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </flux:popover>
                            </flux:dropdown>
                        @endif
                    </x-rail-row>

                    <flux:separator variant="subtle" />

                    @include('partials.dependencies')

                    <flux:separator variant="subtle" />

                    @include('partials.tags')

                    <flux:separator variant="subtle" />

                    <div class="flex flex-col gap-1.5 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Created') }}</flux:text>
                            <flux:text size="sm">{{ $this->story->created_at->format('M j, Y') }}</flux:text>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Updated') }}</flux:text>
                            <flux:text size="sm">{{ $this->story->updated_at->diffForHumans() }}</flux:text>
                        </div>
                    </div>
                </flux:card>
            </aside>
        </div>
    @endif

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
