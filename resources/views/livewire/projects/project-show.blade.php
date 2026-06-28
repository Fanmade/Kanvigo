<div class="app-content mx-auto flex w-full max-w-4xl flex-col gap-6">
    <x-live-refresh :interval-ms="$this->livePollIntervalMs()"/>

    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <div class="flex min-w-0 items-center gap-3">
            <flux:badge color="indigo">{{ $this->project->short_name }}</flux:badge>
            <flux:heading size="xl" class="min-w-0 truncate">{{ $this->project->title }}</flux:heading>
        </div>

        <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
            <x-live-updates-toggle/>
            <livewire:subscriptions.subscription-toggle :subscribable="$this->project"
                                                        :wire:key="'sub-project-'.$this->project->id"/>
            <flux:button size="sm" variant="primary" icon="view-columns" :href="route('project.board', $this->project)"
                         wire:navigate>
                {{ __('Board') }}
            </flux:button>
            @can('manage-tags', $this->project)
                <flux:dropdown align="end">
                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')"
                                 data-test="project-actions"/>
                    <flux:menu>
                        <flux:menu.item icon="tag" :href="route('project.tags', $this->project)" wire:navigate
                                        data-test="manage-tags-link">{{ __('Manage tags') }}</flux:menu.item>
                        @can('manageSettings', $this->project)
                            <flux:menu.item icon="bookmark-square" :href="route('project.task-types', $this->project)"
                                            wire:navigate
                                            data-test="manage-task-types-link">{{ __('Manage types') }}</flux:menu.item>
                            <flux:menu.item icon="pencil-square" wire:click="edit"
                                            data-test="edit-project">{{ __('Edit') }}</flux:menu.item>
                        @endcan
                        @can('manageMembers', $this->project)
                            <flux:menu.item icon="users" wire:click="$set('managingMembers', true)"
                                            data-test="manage-members">{{ __('Manage members') }}</flux:menu.item>
                        @endcan
                        @can('manage-roles', $this->project)
                            <flux:menu.item icon="shield-check" wire:click="$set('managingRoles', true)"
                                            data-test="manage-roles">{{ __('Manage roles') }}</flux:menu.item>
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
            <flux:input wire:model="title" :label="__('Title')"/>
            <flux:input
                wire:model="short_name"
                :label="__('Short name')"
                :description="__('2-4 letters, e.g. ABC. Changing it updates all links to this project.')"
                maxlength="4"
                class="uppercase"
            />
            <x-attachments.rich-editor :label="__('Description')" :mentionables-url="$this->mentionablesUrl"/>
            <x-attachments.upload-button/>
            <flux:input
                type="number"
                min="0"
                wire:model="autoArchiveDays"
                :label="__('Auto-archive Done tasks after (days)')"
                :description="__('Tasks left in Done this many days are archived off the board. Leave blank to use the system default (:days days), or 0 to disable for this project.', ['days' => $this->defaultAutoArchiveDays])"
                :placeholder="$this->defaultAutoArchiveDays"
                data-test="project-auto-archive-days"
            />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('editing', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <x-attachments.dropzone :enabled="$canUpdate">
            <flux:card>
                @if ($this->project->description)
                    <x-expandable-description :content="$this->project->description"/>
                @else
                    <flux:text class="italic text-zinc-400">{{ __('No description yet.') }}</flux:text>
                @endif
            </flux:card>
        </x-attachments.dropzone>

        <x-attachments.list :attachments="$this->attachments"/>
    @endif

    {{-- Tasks (collapsible, collapsed by default) --}}
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3">
            <button
                type="button"
                wire:click="toggleTasksCollapsed"
                class="flex items-center gap-2 text-start"
                aria-expanded="{{ $tasksCollapsed ? 'false' : 'true' }}"
                aria-controls="project-tasks-body"
                data-test="toggle-tasks"
            >
                <flux:icon :name="$tasksCollapsed ? 'chevron-right' : 'chevron-down'" variant="micro"
                           class="text-zinc-400"/>
                <flux:heading size="lg">{{ __('Tasks') }}</flux:heading>
                <flux:badge size="sm" color="zinc"
                            data-test="open-task-count">{{ $this->openTasks->count() }}</flux:badge>
            </button>

            @can('update', $this->project)
                <flux:button size="sm" icon="plus"
                             wire:click="$dispatch('open-create-task', { projectId: {{ $this->project->id }} })"
                             data-test="new-task">{{ __('New task') }}</flux:button>
            @endcan
        </div>

        @unless ($tasksCollapsed)
            <div id="project-tasks-body" class="flex flex-col gap-4" data-test="project-tasks-body">
                {{-- Filters: closed and archived tasks are hidden until opted in;
                     priority/tag/assignee narrow the list like the board. --}}
                <div class="flex flex-wrap items-center gap-3">
                    <flux:dropdown align="start">
                        <flux:button size="sm" icon="funnel" data-test="task-filters">
                            {{ __('Filters') }}
                            @if ($this->activeTaskFilterCount > 0)
                                <flux:badge size="sm" color="blue"
                                            class="ms-1">{{ $this->activeTaskFilterCount }}</flux:badge>
                            @endif
                        </flux:button>

                        <flux:popover class="flex max-h-[28rem] w-72 flex-col gap-4 overflow-y-auto">
                            <flux:switch wire:model.live="showClosed" :label="__('Show closed')" align="left"
                                         data-test="show-closed"/>
                            @if ($this->hasArchivedRootTasks)
                                <flux:switch wire:model.live="showArchived" :label="__('Show archived')" align="left"
                                             data-test="show-archived"/>
                            @endif

                            {{-- A task has one priority, so this is always "any of the selected". --}}
                            <flux:field>
                                <flux:label>{{ __('Priority') }}</flux:label>
                                <flux:checkbox.group wire:model.live="priorityFilters" data-test="priority-filter"
                                                     class="flex flex-col gap-1">
                                    @foreach (\App\Enums\Priority::descending() as $priority)
                                        <flux:checkbox :value="$priority->value" :label="$priority->label()"/>
                                    @endforeach
                                </flux:checkbox.group>
                            </flux:field>

                            @if ($this->projectTags->isNotEmpty())
                                <flux:field>
                                    <div class="flex items-center justify-between gap-2">
                                        <flux:label class="mb-0">{{ __('Tags') }}</flux:label>
                                        <flux:radio.group wire:model.live="tagMatch" variant="segmented" size="sm"
                                                          data-test="tag-match">
                                            <flux:radio value="any">{{ __('Any') }}</flux:radio>
                                            <flux:radio value="all">{{ __('All') }}</flux:radio>
                                        </flux:radio.group>
                                    </div>
                                    <flux:checkbox.group wire:model.live="tagFilters" data-test="tag-filter"
                                                         class="flex max-h-36 flex-col gap-1 overflow-y-auto">
                                        @foreach ($this->projectTags as $tag)
                                            <flux:checkbox :value="$tag->id" :label="$tag->name"/>
                                        @endforeach
                                    </flux:checkbox.group>
                                </flux:field>
                            @endif

                            @if ($this->members->isNotEmpty())
                                <flux:field>
                                    <div class="flex items-center justify-between gap-2">
                                        <flux:label class="mb-0">{{ __('Assignees') }}</flux:label>
                                        <flux:radio.group wire:model.live="assigneeMatch" variant="segmented" size="sm"
                                                          data-test="assignee-match">
                                            <flux:radio value="any">{{ __('Any') }}</flux:radio>
                                            <flux:radio value="all">{{ __('All') }}</flux:radio>
                                        </flux:radio.group>
                                    </div>
                                    <flux:checkbox.group wire:model.live="assigneeFilters" data-test="assignee-filter"
                                                         class="flex max-h-36 flex-col gap-1 overflow-y-auto">
                                        @foreach ($this->members as $member)
                                            <flux:checkbox :value="$member->id" :label="$member->name"/>
                                        @endforeach
                                    </flux:checkbox.group>
                                </flux:field>
                            @endif
                        </flux:popover>
                    </flux:dropdown>
                </div>

                {{-- Open tasks --}}
                <div class="flex flex-col gap-3">
                    @forelse ($this->openTasks as $task)
                        <x-root-task-card :task="$task" :short-name="$this->project->short_name"
                                          :can-archive="$canUpdate" :show-archived="$showArchived"/>
                    @empty
                        <flux:card>
                            <flux:text
                                class="text-zinc-400">{{ __('No open tasks. Create one to get started.') }}</flux:text>
                        </flux:card>
                    @endforelse
                </div>

                {{-- Closed tasks (Done & Canceled) --}}
                @if ($showClosed && $this->completedTasks->isNotEmpty())
                    <div class="flex flex-col gap-3" data-test="closed-tasks">
                        <flux:heading size="sm"
                                      class="text-zinc-500 dark:text-zinc-400">{{ __('Closed tasks') }}</flux:heading>

                        @foreach ($this->completedTasks as $task)
                            <x-root-task-card :task="$task" :short-name="$this->project->short_name"
                                              :can-archive="$canUpdate" :show-archived="$showArchived"/>
                        @endforeach
                    </div>
                @endif

                {{-- Archived tasks --}}
                @if ($showArchived && $this->archivedTasks->isNotEmpty())
                    <div class="flex flex-col gap-3" data-test="archived-tasks">
                        <flux:heading size="sm"
                                      class="text-zinc-500 dark:text-zinc-400">{{ __('Archived tasks') }}</flux:heading>

                        @foreach ($this->archivedTasks as $task)
                            <x-root-task-card :task="$task" :short-name="$this->project->short_name"
                                              :can-archive="$canUpdate" :show-archived="$showArchived"/>
                        @endforeach
                    </div>
                @endif
            </div>
        @endunless
    </div>

    {{-- Notes shared with the project (public notes, read-only for members). --}}
    @if ($this->publicNotes->isNotEmpty())
        <div data-test="project-notes">
            <flux:heading size="lg" class="mb-2">{{ __('Notes') }}</flux:heading>

            <div class="flex flex-col gap-3">
                @foreach ($this->publicNotes as $note)
                    <flux:card class="flex flex-col gap-2" wire:key="public-note-{{ $note->id }}"
                               data-test="public-note-{{ $note->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <flux:heading size="sm" class="truncate">{{ $note->title }}</flux:heading>
                                <flux:text size="xs"
                                           class="text-zinc-400">{{ __('by :name', ['name' => $note->user?->name ?? __('Deleted user')]) }}</flux:text>
                            </div>

                            @if ($note->user_id === auth()->id())
                                <flux:dropdown align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal"
                                                 :aria-label="__('Note actions')"
                                                 data-test="public-note-actions-{{ $note->id }}"/>
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square"
                                                        wire:click="$dispatch('open-create-note', { noteId: {{ $note->id }} })">{{ __('Edit') }}</flux:menu.item>
                                        <flux:menu.item icon="lock-closed"
                                                        wire:click="toggleNoteVisibility({{ $note->id }})"
                                                        data-test="unshare-note-{{ $note->id }}">{{ __('Make private') }}</flux:menu.item>
                                        <flux:menu.separator/>
                                        <flux:menu.item icon="trash" variant="danger"
                                                        wire:click="deleteNote({{ $note->id }})"
                                                        wire:confirm="{{ __('Delete this note?') }}"
                                                        data-test="delete-public-note-{{ $note->id }}">{{ __('Delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>

                        @if ($note->body)
                            <x-expandable-description :content="$note->body"/>
                        @endif
                    </flux:card>
                @endforeach
            </div>
        </div>
    @endif

    <livewire:comments.comment-list :commentable="$this->project" :wire:key="'comments-project-'.$this->project->id"/>

    @can('manageMembers', $this->project)
        <flux:modal wire:model="managingMembers" class="md:w-96" data-test="members-modal">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Manage members') }}</flux:heading>

                {{-- Add an existing user to the project. --}}
                <div class="flex flex-col gap-1">
                    <flux:input
                        size="sm"
                        wire:model.live.debounce.300ms="memberQuery"
                        :placeholder="__('Add a user by name or email')"
                        data-test="member-search"
                    />

                    @if ($this->addableUsers->isNotEmpty())
                        <div
                            class="flex max-h-48 flex-col gap-0.5 overflow-y-auto rounded-lg border border-zinc-200 p-1 dark:border-white/10"
                            data-test="addable-users">
                            @foreach ($this->addableUsers as $user)
                                <flux:button
                                    type="button"
                                    size="xs"
                                    variant="ghost"
                                    class="justify-start!"
                                    wire:click="addMember({{ $user->id }})"
                                    data-test="add-user-{{ $user->id }}"
                                >
                                    <span class="truncate">{{ $user->name }} <span
                                            class="text-zinc-400">{{ $user->email }}</span></span>
                                </flux:button>
                            @endforeach
                        </div>
                    @elseif (trim($this->memberQuery) !== '')
                        <flux:text size="xs" class="text-zinc-400">{{ __('No matching users.') }}</flux:text>
                    @endif
                </div>

                <flux:separator/>

                <div class="flex flex-col gap-2" data-test="members-list">
                    @foreach ($this->members as $member)
                        @php($heldNames = $member->roles->pluck('name'))
                        @php($readonly = ($member->id === auth()->id() || $heldNames->contains('owner')))
                        @php($addable = $this->assignableRoles->reject(fn ($role) => $heldNames->contains($role->name)))
                        <div class="flex items-start justify-between gap-3" wire:key="member-{{ $member->id }}"
                             data-test="member-row-{{ $member->id }}">
                            <x-user-link :user="$member" class="min-w-0 truncate pt-1 text-sm">{{ $member->name }}</x-user-link>

                            <div class="flex flex-col items-end gap-1.5">
                                <div class="flex flex-wrap justify-end gap-1"
                                     data-test="member-roles-{{ $member->id }}">
                                    @forelse ($member->roles as $role)
                                        <flux:badge size="sm"
                                                    data-test="member-role-{{ $member->id }}-{{ $role->name }}">
                                            {{ $this->roleLabel($role->name) }}
                                            @unless ($readonly)
                                                <flux:badge.close
                                                    wire:click="removeMemberRole({{ $member->id }}, '{{ $role->name }}')"
                                                    :aria-label="__('Remove role')"
                                                    data-test="remove-member-role-{{ $member->id }}-{{ $role->name }}"
                                                />
                                            @endunless
                                        </flux:badge>
                                    @empty
                                        <flux:text size="sm" variant="subtle">{{ __('No roles') }}</flux:text>
                                    @endforelse
                                </div>

                                @unless ($readonly)
                                    <div class="flex items-center gap-1.5">
                                        @if ($addable->isNotEmpty())
                                            <flux:select
                                                size="sm"
                                                class="max-w-32"
                                                :placeholder="__('Add role…')"
                                                wire:change="addMemberRole({{ $member->id }}, $event.target.value)"
                                                data-test="add-member-role-{{ $member->id }}"
                                            >
                                                @foreach ($addable as $assignable)
                                                    <flux:select.option
                                                        value="{{ $assignable->name }}">{{ $this->roleLabel($assignable->name) }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        @endif

                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="ghost"
                                            icon="x-mark"
                                            :aria-label="__('Remove member')"
                                            wire:click="removeMember({{ $member->id }})"
                                            data-test="remove-member-{{ $member->id }}"
                                        />
                                    </div>
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('manage-roles', $this->project)
        <flux:modal wire:model="managingRoles" class="w-full max-w-5xl" data-test="roles-modal">
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Manage roles') }}</flux:heading>

                @if ($this->managingRoles)
                    <livewire:projects.project-roles :project="$this->project" :wire:key="'roles-'.$this->project->id"/>
                @endif
            </div>
        </flux:modal>
    @endcan
</div>
