<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex items-center justify-between gap-2">
        <div class="flex min-w-0 items-center gap-2 text-sm">
            <a href="{{ route('project.show', $this->task->story->project) }}" wire:navigate class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                {{ $this->task->story->project->short_name }}
            </a>
            <span class="text-zinc-300">/</span>
            <a href="{{ route('story.show', ['short_name' => $this->task->story->project->short_name, 'story_number' => $this->task->story->story_number]) }}" wire:navigate class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                {{ $this->task->story->reference }}
            </a>
            <span class="text-zinc-300">/</span>
            <span class="font-mono text-zinc-400">{{ $this->task->reference }}</span>
        </div>
        <livewire:subscriptions.subscription-toggle :subscribable="$this->task" :wire:key="'sub-task-'.$this->task->id" />
    </div>

    @php($canUpdate = auth()->user()->can('update', $this->task))

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
                    <flux:heading size="xl">{{ $this->task->title }}</flux:heading>
                    @can('update', $this->task)
                        <flux:button size="sm" icon="pencil-square" variant="ghost" wire:click="edit" data-test="edit-task">{{ __('Edit') }}</flux:button>
                    @endcan
                </div>

                <div class="flex flex-wrap items-center gap-1">
                    <x-due-date-badge :date="$this->task->due_date" />
                </div>

                <x-attachments.dropzone :enabled="$canUpdate">
                    <flux:card>
                        @if ($this->task->description)
                            <x-markdown :content="$this->task->description" class="max-h-96 overflow-y-auto" />
                        @else
                            <flux:text class="italic text-zinc-400">{{ __('No description yet.') }}</flux:text>
                        @endif
                    </flux:card>
                </x-attachments.dropzone>

                <x-attachments.list :attachments="$this->attachments" />

                <livewire:comments.comment-list :commentable="$this->task" :wire:key="'comments-task-'.$this->task->id" />

                <livewire:activity.activity-feed :subject="$this->task" :wire:key="'activity-task-'.$this->task->id" />
            </div>

            {{-- Metadata rail --}}
            <aside class="w-full shrink-0 lg:w-72">
                <flux:card class="flex flex-col gap-4">
                    <x-rail-row :label="__('Status')">
                        <x-status-control
                            :status="$this->task->status"
                            :can-edit="auth()->user()->can('updateStatus', $this->task)"
                        />
                    </x-rail-row>

                    <x-rail-row :label="__('Priority')">
                        <x-priority-control :priority="$this->task->priority" :can-edit="$canUpdate" />
                    </x-rail-row>

                    <x-rail-row :label="__('Assignees')">
                        @if ($this->task->assignees->isNotEmpty())
                            <flux:avatar.group>
                                @foreach ($this->task->assignees as $assignee)
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
                            <flux:text size="sm">{{ $this->task->created_at->format('M j, Y') }}</flux:text>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Updated') }}</flux:text>
                            <flux:text size="sm">{{ $this->task->updated_at->diffForHumans() }}</flux:text>
                        </div>
                    </div>
                </flux:card>
            </aside>
        </div>
    @endif
</div>
