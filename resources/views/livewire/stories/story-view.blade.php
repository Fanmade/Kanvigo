<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
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
            <flux:input wire:model="tags" :label="__('Tags')" :description="__('Optional, comma-separated')" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('editing', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <div class="flex items-start justify-between gap-4">
            <flux:heading size="xl">{{ $this->story->title }}</flux:heading>
            @can('update', $this->story)
                <flux:button size="sm" icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
            @endcan
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1">
                <x-priority-badge :priority="$this->story->priority" />
                <x-due-date-badge :date="$this->story->due_date" />
                <x-tag-badges :tags="$this->story->tags" />
            </div>
            <x-story-progress :progress="$this->story->progress()" />
        </div>

        <x-attachments.dropzone :enabled="$canUpdate">
            <flux:card>
                @if ($this->story->description)
                    <x-markdown :content="$this->story->description" class="max-h-96 overflow-y-auto" />
                @else
                    <flux:text class="italic text-zinc-400">{{ __('No description yet.') }}</flux:text>
                @endif
            </flux:card>
        </x-attachments.dropzone>

        <x-attachments.list :attachments="$this->attachments" />
    @endif

    @can('update', $this->story)
        <flux:field>
            <flux:label>{{ __('Priority') }}</flux:label>
            <flux:select wire:model.live="priority">
                @foreach (\App\Enums\Priority::ordered() as $priority)
                    <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Assignees') }}</flux:label>
            <flux:select variant="listbox" multiple wire:model.live="assigneeIds" :placeholder="__('Assign members')">
                @foreach ($this->members as $member)
                    <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>
    @endcan

    <div>
        <div class="mb-2 flex items-center justify-between">
            <flux:heading size="sm">{{ __('Tasks') }}</flux:heading>
            @can('update', $this->story)
                <flux:button size="sm" icon="plus" wire:click="openTaskModal" data-test="new-task">{{ __('New task') }}</flux:button>
            @endcan
        </div>
        <flux:card class="flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700">
            @forelse ($this->story->tasks as $task)
                <a
                    href="{{ route('task.show', ['short_name' => $this->story->project->short_name, 'story_number' => $this->story->story_number, 'task_number' => $task->task_number]) }}"
                    wire:navigate
                    class="flex items-center justify-between gap-2 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                >
                    <div class="flex items-center gap-2">
                        <flux:text size="xs" class="font-mono text-zinc-400">{{ $task->reference }}</flux:text>
                        <span class="text-sm">{{ $task->title }}</span>
                    </div>
                    <flux:badge size="sm" :color="$task->status->color()" :icon="$task->status->icon()">{{ $task->status->label() }}</flux:badge>
                </a>
            @empty
                <flux:text size="sm" class="px-4 py-3 text-zinc-400">{{ __('No tasks yet.') }}</flux:text>
            @endforelse
        </flux:card>
    </div>

    <livewire:comments.comment-list :commentable="$this->story" :wire:key="'comments-story-'.$this->story->id" />

    <livewire:activity.activity-feed :subject="$this->story" :wire:key="'activity-story-'.$this->story->id" />

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
