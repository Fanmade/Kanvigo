<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex items-center justify-between gap-2">
        @php($shortName = $this->task->project->short_name)
        <div class="flex min-w-0 flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('project.show', $this->task->project) }}" wire:navigate class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
                {{ $shortName }}
            </a>
            {{-- The task's place in the tree: each open ancestor, root first. --}}
            @foreach ($this->task->ancestors->sortBy($this->task->getDepthName()) as $ancestor)
                <span class="text-zinc-300">/</span>
                <a
                    href="{{ route('task.show', ['short_name' => $shortName, 'task_number' => $ancestor->task_number]) }}"
                    wire:navigate
                    class="font-mono text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                    data-test="ancestor-{{ $ancestor->id }}"
                >
                    {{ $shortName }}-{{ $ancestor->task_number }}
                </a>
            @endforeach
            <span class="text-zinc-300">/</span>
            <span class="font-mono text-zinc-400">{{ $this->task->reference }}</span>
        </div>
        <livewire:subscriptions.subscription-toggle :subscribable="$this->task" :wire:key="'sub-task-'.$this->task->id" />
    </div>

    @if ($parentBumpUndoStatus !== '')
        <div
            class="flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm dark:border-amber-400/20 dark:bg-amber-400/10"
            data-test="parent-bump-banner"
        >
            <flux:text size="sm">{{ __('The parent task was moved to In progress.') }}</flux:text>
            <div class="flex items-center gap-2">
                <flux:button size="xs" variant="ghost" wire:click="undoParentBump" data-test="undo-parent-bump">{{ __('Undo') }}</flux:button>
                <flux:button size="xs" variant="subtle" icon="x-mark" :aria-label="__('Dismiss')" wire:click="dismissParentBump" />
            </div>
        </div>
    @endif

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
                            <x-expandable-description :content="$this->task->description" />
                        @else
                            <flux:text class="italic text-zinc-400">{{ __('No description yet.') }}</flux:text>
                        @endif
                    </flux:card>
                </x-attachments.dropzone>

                <x-attachments.list :attachments="$this->attachments" />

                {{-- Subtasks: the direct children, with a progress rollup over the whole
                     subtree. Hidden entirely when there is nothing to show and nothing can
                     be added (e.g. a task at the maximum nesting depth). --}}
                @if ($this->task->children->isNotEmpty() || ($canUpdate && $this->canAddSubtask))
                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <flux:heading size="sm">{{ __('Subtasks') }}</flux:heading>
                            <div class="flex items-center gap-3">
                                <x-task-progress :progress="$this->task->progress()" />
                                @if ($canUpdate && $this->canAddSubtask)
                                    <flux:button size="sm" icon="plus" wire:click="openSubtaskModal" data-test="new-subtask">{{ __('New subtask') }}</flux:button>
                                @endif
                            </div>
                        </div>

                        <flux:card class="flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700">
                            @forelse ($this->task->children as $child)
                                <a
                                    href="{{ route('task.show', ['short_name' => $shortName, 'task_number' => $child->task_number]) }}"
                                    wire:navigate
                                    class="flex items-center justify-between gap-2 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                    data-test="subtask-{{ $child->id }}"
                                >
                                    <div class="flex min-w-0 items-center gap-2">
                                        <flux:text size="xs" class="font-mono text-zinc-400">{{ $shortName }}-{{ $child->task_number }}</flux:text>
                                        <span @class(['text-sm', 'truncate text-zinc-400' => $child->isArchived()])>{{ $child->title }}</span>
                                    </div>
                                    <flux:badge size="sm" :color="$child->status->color()" :icon="$child->status->icon()">{{ $child->status->label() }}</flux:badge>
                                </a>
                            @empty
                                <flux:text size="sm" class="px-4 py-3 text-zinc-400">{{ __('No subtasks yet.') }}</flux:text>
                            @endforelse
                        </flux:card>
                    </div>
                @endif

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

    {{-- Create subtask --}}
    <flux:modal wire:model="showSubtaskModal" class="md:w-96">
        <form wire:submit="createSubtask" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('New subtask') }}</flux:heading>
            <flux:input wire:model="subtaskTitle" :label="__('Title')" data-test="subtask-title" />
            <flux:textarea wire:model="subtaskDescription" :label="__('Description')" rows="3" />
            <flux:select wire:model="subtaskPriority" :label="__('Priority')" data-test="subtask-priority">
                @foreach (\App\Enums\Priority::ordered() as $priority)
                    <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model="subtaskDueDate" :label="__('Due date')" :description="__('Optional')" />
            <flux:select wire:model="subtaskStatus" :label="__('Status')">
                @foreach (\App\Enums\Status::columns() as $status)
                    <flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="create-subtask">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="confirmingCascade" wire:close="abortCascade" class="md:w-96" data-test="cascade-modal">
        @php($canceling = $this->pendingStatusEnum === \App\Enums\Status::Canceled)
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">
                {{ $canceling ? __('Cancel the subtasks too?') : __('Mark the subtasks done too?') }}
            </flux:heading>
            <flux:text>
                {{ $canceling
                    ? __('This task has :count open subtask(s). Cancel them as well, or cancel only this task?', ['count' => $this->openSubtaskCount])
                    : __('This task has :count open subtask(s). Mark them done as well, or complete only this task?', ['count' => $this->openSubtaskCount]) }}
            </flux:text>

            <flux:checkbox wire:model="rememberCascadeChoice" :label="__('Remember my choice')" data-test="cascade-remember" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="abortCascade">
                    {{ $canceling ? __('Keep open') : __('Cancel') }}
                </flux:button>
                <flux:button type="button" variant="filled" wire:click="declineCascade" data-test="cascade-decline">{{ __('Only this task') }}</flux:button>
                @if ($canceling)
                    <flux:button type="button" variant="danger" wire:click="confirmCascade" data-test="cascade-confirm">{{ __('Cancel all') }}</flux:button>
                @else
                    <flux:button type="button" variant="primary" wire:click="confirmCascade" data-test="cascade-confirm">{{ __('Mark all done') }}</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
