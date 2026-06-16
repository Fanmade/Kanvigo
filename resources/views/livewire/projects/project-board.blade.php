<div class="flex h-full flex-col gap-4">
    <div class="flex flex-col gap-1">
        <a href="{{ route('project.show', $this->project) }}" wire:navigate class="flex w-fit items-center gap-1 text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">
            <flux:icon.chevron-left variant="micro" />
            {{ $this->project->short_name }} · {{ $this->project->title }}
        </a>

        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('Board') }}</flux:heading>

            <div class="flex gap-2">
                <flux:button icon="plus" wire:click="$set('showStoryModal', true)">{{ __('New story') }}</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="openTaskModal">{{ __('New task') }}</flux:button>
            </div>
        </div>
    </div>

    <x-kanban-board :columns="$this->columns" />

    {{-- Create story --}}
    <flux:modal wire:model="showStoryModal" class="md:w-96">
        <form wire:submit="createStory" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('New story') }}</flux:heading>
            <flux:input wire:model="storyTitle" :label="__('Title')" />
            <flux:textarea wire:model="storyDescription" :label="__('Description')" rows="3" />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Create task --}}
    <flux:modal wire:model="showTaskModal" class="md:w-96">
        <form wire:submit="createTask" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('New task') }}</flux:heading>

            @if ($this->stories->isEmpty())
                <flux:callout variant="warning">
                    {{ __('Create a story first before adding tasks.') }}
                </flux:callout>
            @else
                <flux:select wire:model="taskStoryId" :label="__('Story')">
                    @foreach ($this->stories as $story)
                        <flux:select.option :value="$story->id">{{ $story->reference }} · {{ $story->title }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="taskTitle" :label="__('Title')" />
                <flux:textarea wire:model="taskDescription" :label="__('Description')" rows="3" />

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
            @endif
        </form>
    </flux:modal>
</div>
