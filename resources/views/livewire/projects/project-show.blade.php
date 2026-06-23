<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <x-live-refresh :interval-ms="$this->livePollIntervalMs()" />

    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <div class="flex min-w-0 items-center gap-3">
            <flux:badge color="indigo">{{ $this->project->short_name }}</flux:badge>
            <flux:heading size="xl" class="min-w-0 truncate">{{ $this->project->title }}</flux:heading>
        </div>

        <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
            <x-live-updates-toggle />
            <livewire:subscriptions.subscription-toggle :subscribable="$this->project" :wire:key="'sub-project-'.$this->project->id" />
            <flux:button size="sm" variant="primary" icon="view-columns" :href="route('project.board', $this->project)" wire:navigate>
                {{ __('Board') }}
            </flux:button>
            @can('manageSettings', $this->project)
                <flux:dropdown align="end">
                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" data-test="project-actions" />
                    <flux:menu>
                        <flux:menu.item icon="pencil-square" wire:click="edit" data-test="edit-project">{{ __('Edit') }}</flux:menu.item>
                        @can('manageMembers', $this->project)
                            <flux:menu.item icon="users" wire:click="$set('managingMembers', true)" data-test="manage-members">{{ __('Manage members') }}</flux:menu.item>
                        @endcan
                    </flux:menu>
                </flux:dropdown>
            @endcan
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
            <x-attachments.rich-editor :label="__('Description')" />
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
                    <x-expandable-description :content="$this->project->description" />
                @else
                    <flux:text class="italic text-zinc-400">{{ __('No description yet.') }}</flux:text>
                @endif
            </flux:card>
        </x-attachments.dropzone>

        <x-attachments.list :attachments="$this->attachments" />
    @endif

    {{-- Open tasks --}}
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Open tasks') }}</flux:heading>
            <div class="flex items-center gap-3">
                @if ($this->archivedTasks->isNotEmpty())
                    <flux:switch wire:model.live="showArchived" :label="__('Show archived')" align="left" data-test="show-archived" />
                @endif
                @can('update', $this->project)
                    <flux:button size="sm" icon="plus" wire:click="$dispatch('open-create-task', { projectId: {{ $this->project->id }} })" data-test="new-task">{{ __('New task') }}</flux:button>
                @endcan
            </div>
        </div>

        @forelse ($this->openTasks as $task)
            <x-root-task-card :task="$task" :short-name="$this->project->short_name" :can-archive="$canUpdate" :show-archived="$showArchived" />
        @empty
            <flux:card>
                <flux:text class="text-zinc-400">{{ __('No open tasks. Create one to get started.') }}</flux:text>
            </flux:card>
        @endforelse
    </div>

    {{-- Completed tasks --}}
    @if ($this->completedTasks->isNotEmpty())
        <div class="flex flex-col gap-3">
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">{{ __('Completed tasks') }}</flux:heading>

            @foreach ($this->completedTasks as $task)
                <x-root-task-card :task="$task" :short-name="$this->project->short_name" :can-archive="$canUpdate" :show-archived="$showArchived" />
            @endforeach
        </div>
    @endif

    {{-- Archived tasks --}}
    @if ($showArchived && $this->archivedTasks->isNotEmpty())
        <div class="flex flex-col gap-3" data-test="archived-tasks">
            <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">{{ __('Archived tasks') }}</flux:heading>

            @foreach ($this->archivedTasks as $task)
                <x-root-task-card :task="$task" :short-name="$this->project->short_name" :can-archive="$canUpdate" :show-archived="$showArchived" />
            @endforeach
        </div>
    @endif

    {{-- Notes shared with the project (public notes, read-only for members). --}}
    @if ($this->publicNotes->isNotEmpty())
        <div data-test="project-notes">
            <flux:heading size="lg" class="mb-2">{{ __('Notes') }}</flux:heading>

            <div class="flex flex-col gap-3">
                @foreach ($this->publicNotes as $note)
                    <flux:card class="flex flex-col gap-2" wire:key="public-note-{{ $note->id }}" data-test="public-note-{{ $note->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <flux:heading size="sm" class="truncate">{{ $note->title }}</flux:heading>
                                <flux:text size="xs" class="text-zinc-400">{{ __('by :name', ['name' => $note->user?->name ?? __('Deleted user')]) }}</flux:text>
                            </div>

                            @if ($note->user_id === auth()->id())
                                <flux:dropdown align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Note actions')" data-test="public-note-actions-{{ $note->id }}" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="$dispatch('open-create-note', { noteId: {{ $note->id }} })">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.item icon="lock-closed" wire:click="toggleNoteVisibility({{ $note->id }})" data-test="unshare-note-{{ $note->id }}">{{ __('Make private') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteNote({{ $note->id }})" wire:confirm="{{ __('Delete this note?') }}" data-test="delete-public-note-{{ $note->id }}">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>

                        @if ($note->body)
                            <x-expandable-description :content="$note->body" />
                        @endif
                    </flux:card>
                @endforeach
            </div>
        </div>
    @endif

    <livewire:comments.comment-list :commentable="$this->project" :wire:key="'comments-project-'.$this->project->id" />

    @can('manageMembers', $this->project)
        <flux:modal wire:model="managingMembers" class="md:w-96" data-test="members-modal">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Manage members') }}</flux:heading>

                <div class="flex flex-col gap-2" data-test="members-list">
                    @foreach ($this->members as $member)
                        @php($role = \App\Enums\ProjectRole::from($member->pivot->role))
                        <div class="flex items-center justify-between gap-3" wire:key="member-{{ $member->id }}" data-test="member-row-{{ $member->id }}">
                            <flux:text class="min-w-0 truncate">{{ $member->name }}</flux:text>

                            @if ($role === \App\Enums\ProjectRole::Owner || $member->id === auth()->id())
                                <flux:badge size="sm" data-test="member-role-{{ $member->id }}">{{ $role->label() }}</flux:badge>
                            @else
                                <flux:select
                                    size="sm"
                                    class="max-w-32"
                                    wire:change="setMemberRole({{ $member->id }}, $event.target.value)"
                                    data-test="member-role-select-{{ $member->id }}"
                                >
                                    <flux:select.option value="member" :selected="$role === \App\Enums\ProjectRole::Member">{{ __('Member') }}</flux:select.option>
                                    <flux:select.option value="admin" :selected="$role === \App\Enums\ProjectRole::Admin">{{ __('Admin') }}</flux:select.option>
                                </flux:select>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </flux:modal>
    @endcan
</div>
