<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
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
            <flux:input wire:model="keywords" :label="__('Keywords')" :description="__('Optional, comma-separated')" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('editing', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <div class="flex items-start justify-between gap-4">
            <flux:heading size="xl">{{ $this->task->title }}</flux:heading>
            @can('update', $this->task)
                <flux:button size="sm" icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
            @endcan
        </div>

        <div class="flex flex-wrap items-center gap-1">
            <x-due-date-badge :date="$this->task->due_date" />
            <x-keyword-badges :keywords="$this->task->keywords" />
        </div>

        <x-attachments.dropzone :enabled="$canUpdate">
            <flux:card>
                @if ($this->task->description)
                    <x-markdown :content="$this->task->description" />
                @else
                    <flux:text class="italic text-zinc-400">{{ __('No description yet.') }}</flux:text>
                @endif
            </flux:card>
        </x-attachments.dropzone>

        <x-attachments.list :attachments="$this->attachments" />
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:field>
            <div class="flex items-center gap-2">
                <flux:label>{{ __('Status') }}</flux:label>
                <flux:badge size="sm" :color="$this->task->status->color()" :icon="$this->task->status->icon()">
                    {{ $this->task->status->label() }}
                </flux:badge>
            </div>
            <flux:select wire:model.live="status" :disabled="! auth()->user()->can('updateStatus', $this->task)">
                @foreach (\App\Enums\Status::columns() as $status)
                    <flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field>
            <div class="flex items-center gap-2">
                <flux:label>{{ __('Priority') }}</flux:label>
                <x-priority-badge :priority="$this->task->priority" />
            </div>
            <flux:select wire:model.live="priority" :disabled="! $canUpdate">
                @foreach (\App\Enums\Priority::ordered() as $priority)
                    <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Assignees') }}</flux:label>
            <flux:select variant="listbox" multiple wire:model.live="assigneeIds" :placeholder="__('Assign members')" :disabled="! $canUpdate">
                @foreach ($this->members as $member)
                    <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>
    </div>

    <livewire:comments.comment-list :commentable="$this->task" :wire:key="'comments-task-'.$this->task->id" />

    <livewire:activity.activity-feed :subject="$this->task" :wire:key="'activity-task-'.$this->task->id" />
</div>
