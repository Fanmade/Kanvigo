<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <flux:badge color="indigo">{{ $this->project->short_name }}</flux:badge>
            <flux:heading size="xl">{{ $this->project->title }}</flux:heading>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <livewire:subscriptions.subscription-toggle :subscribable="$this->project" :wire:key="'sub-project-'.$this->project->id" />
            @can('update', $this->project)
                <flux:button size="sm" icon="pencil-square" wire:click="edit">{{ __('Edit') }}</flux:button>
            @endcan
            <flux:button size="sm" variant="primary" icon="view-columns" :href="route('project.board', $this->project)" wire:navigate>
                {{ __('Board') }}
            </flux:button>
        </div>
    </div>

    {{-- Description --}}
    @php($canUpdate = auth()->user()->can('update', $this->project))

    @if ($editing)
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:input wire:model="title" :label="__('Title')" />
            <flux:input
                wire:model="short_name"
                :label="__('Short name')"
                :description="__('2-4 letters, e.g. ABC. Changing it updates all links to this project.')"
                maxlength="4"
                class="uppercase"
            />
            <x-attachments.markdown-editor :label="__('Description')" />
            <x-attachments.upload-button />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('editing', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <x-attachments.dropzone :enabled="$canUpdate">
            <flux:card>
                @if ($this->project->description)
                    <x-markdown :content="$this->project->description" class="max-h-96 overflow-y-auto" />
                @else
                    <flux:text class="italic text-zinc-400">{{ __('No description yet.') }}</flux:text>
                @endif
            </flux:card>
        </x-attachments.dropzone>

        <x-attachments.list :attachments="$this->attachments" />
    @endif

    {{-- Open stories --}}
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Open stories') }}</flux:heading>
            @can('update', $this->project)
                <flux:button size="sm" icon="plus" wire:click="$set('showStoryModal', true)">{{ __('New story') }}</flux:button>
            @endcan
        </div>

        @forelse ($this->openStories as $story)
            <x-story-card :story="$story" :short-name="$this->project->short_name" :hide-finished-tasks="true" />
        @empty
            <flux:card>
                <flux:text class="text-zinc-400">{{ __('No open stories. Create one to get started.') }}</flux:text>
            </flux:card>
        @endforelse
    </div>

    {{-- Completed stories --}}
    @if ($this->completedStories->isNotEmpty())
        <div class="flex flex-col gap-3">
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">{{ __('Completed stories') }}</flux:heading>

            @foreach ($this->completedStories as $story)
                <x-story-card :story="$story" :short-name="$this->project->short_name" />
            @endforeach
        </div>
    @endif

    <livewire:comments.comment-list :commentable="$this->project" :wire:key="'comments-project-'.$this->project->id" />

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
</div>
