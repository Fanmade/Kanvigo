<div>
    <flux:modal wire:model="show" class="w-full max-w-5xl" data-test="create-task-modal">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('New task') }}</flux:heading>

            {{-- Project + parent task --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if (count($this->projects) > 1)
                    <flux:select wire:model.live="projectId" :label="__('Project')" :placeholder="__('Select a project')" data-test="create-task-project">
                        @foreach ($this->projects as $project)
                            <flux:select.option :value="$project->id">{{ $project->short_name }} · {{ $project->title }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($this->projectId)
                    <flux:select
                        variant="listbox"
                        searchable
                        wire:model="parentId"
                        :label="__('Parent task')"
                        :placeholder="__('None (top-level task)')"
                        @class(['sm:col-span-2' => count($this->projects) <= 1])
                        data-test="create-task-parent"
                    >
                        <flux:select.option :value="null">{{ __('None (top-level task)') }}</flux:select.option>
                        @foreach ($this->parentOptions as $id => $label)
                            <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            <flux:input wire:model="title" :label="__('Title')" data-test="create-task-title" />

            <div>
                <div class="mb-1 flex items-center justify-between">
                    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Description') }}</span>
                    <div class="flex gap-1">
                        <flux:button type="button" size="xs" :variant="$showPreview ? 'ghost' : 'filled'" wire:click="$set('showPreview', false)" data-test="create-task-write">{{ __('Write') }}</flux:button>
                        <flux:button type="button" size="xs" :variant="$showPreview ? 'filled' : 'ghost'" wire:click="$set('showPreview', true)" data-test="create-task-preview">{{ __('Preview') }}</flux:button>
                    </div>
                </div>

                @if ($showPreview)
                    <div class="min-h-[6rem] rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700" data-test="create-task-preview-content">
                        @if (trim($description) !== '')
                            <x-markdown :content="$description" />
                        @else
                            <flux:text class="text-sm text-zinc-400">{{ __('Nothing to preview.') }}</flux:text>
                        @endif
                    </div>
                @else
                    <flux:textarea wire:model="description" rows="5" :description="__('Markdown supported.')" data-test="create-task-description" />
                @endif
            </div>

            {{-- Priority + status --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="priority" :label="__('Priority')" data-test="create-task-priority">
                    @foreach (\App\Enums\Priority::ordered() as $priority)
                        <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="status" :label="__('Status')" data-test="create-task-status">
                    @foreach (\App\Enums\Status::columns() as $status)
                        <flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            {{-- Metadata rail: due date, tags, assignees --}}
            <flux:card class="flex flex-col gap-4">
                <x-rail-row :label="__('Due date')">
                    @if ($dueDate)
                        <flux:badge size="sm" color="zinc" variant="pill" data-test="create-task-due-date-badge">
                            {{ \Illuminate\Support\Carbon::parse($dueDate)->format('M j, Y') }}
                            <flux:badge.close wire:click="$set('dueDate', '')" :aria-label="__('Clear due date')" data-test="create-task-clear-due-date" />
                        </flux:badge>
                    @else
                        <flux:text size="sm" class="text-zinc-400">{{ __('None') }}</flux:text>
                    @endif

                    <flux:dropdown align="end" data-test="create-task-due-date-control">
                        <flux:button size="xs" variant="subtle" :icon="$dueDate ? 'pencil' : 'plus'" :aria-label="__('Set due date')" />
                        <flux:popover class="flex w-64 flex-col gap-2">
                            <flux:input type="date" wire:model.live="dueDate" :label="__('Due date')" data-test="create-task-due-date" />
                        </flux:popover>
                    </flux:dropdown>
                </x-rail-row>

                <flux:separator variant="subtle" />

                {{-- Tags --}}
                <div x-data="{ adding: false }" class="flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-3">
                        <flux:text size="sm" class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ __('Tags') }}</flux:text>
                        <div class="flex min-w-0 flex-wrap items-center justify-end gap-1" data-test="create-task-tags">
                            @foreach ($tagNames as $index => $name)
                                <flux:badge size="sm" color="zinc" variant="pill" wire:key="draft-tag-{{ $index }}">
                                    {{ $name }}
                                    <flux:badge.close wire:click="removeDraftTag({{ $index }})" :aria-label="__('Remove tag')" data-test="create-task-remove-tag-{{ $index }}" />
                                </flux:badge>
                            @endforeach
                            <flux:button
                                type="button"
                                size="xs"
                                variant="subtle"
                                icon="plus"
                                :aria-label="__('Add tag')"
                                x-on:click="adding = ! adding; $nextTick(() => $refs.tagInput?.querySelector('input')?.focus())"
                                data-test="create-task-add-tag"
                            />
                        </div>
                    </div>

                    <div x-show="adding" x-cloak x-ref="tagInput" class="flex flex-col gap-1">
                        <flux:input
                            size="sm"
                            wire:model.live.debounce.200ms="tagQuery"
                            :placeholder="__('Find or create a tag')"
                            x-on:keydown.escape="adding = false"
                            data-test="create-task-tag-input"
                        />

                        @if (trim($tagQuery) !== '')
                            <div class="flex flex-col gap-0.5" role="listbox">
                                @foreach ($this->tagSuggestions as $index => $suggestion)
                                    <flux:button
                                        type="button"
                                        size="xs"
                                        variant="ghost"
                                        class="justify-start!"
                                        wire:click="addSuggestedTag({{ $index }})"
                                        data-test="create-task-tag-suggestion-{{ \Illuminate\Support\Str::slug($suggestion['name']) }}"
                                    >
                                        <x-tag-dot :color="$suggestion['color']" class="me-1.5 size-2" />{{ $suggestion['name'] }}
                                    </flux:button>
                                @endforeach

                                @if ($this->canCreateTag)
                                    <flux:button
                                        type="button"
                                        size="xs"
                                        variant="ghost"
                                        icon="plus"
                                        class="justify-start!"
                                        wire:click="createDraftTag"
                                        data-test="create-task-tag-create"
                                    >
                                        {{ __('Create') }} “{{ trim($tagQuery) }}”
                                    </flux:button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                @if ($this->projectId && $this->members->isNotEmpty())
                    <flux:separator variant="subtle" />

                    <x-rail-row :label="__('Assignees')">
                        @php($selectedAssignees = $this->members->whereIn('id', $assigneeIds))

                        @if ($selectedAssignees->isNotEmpty())
                            <flux:avatar.group>
                                @foreach ($selectedAssignees as $assignee)
                                    <x-user-avatar :user="$assignee" circle :tooltip="$assignee->name" />
                                @endforeach
                            </flux:avatar.group>
                        @else
                            <flux:text size="sm" class="text-zinc-400">{{ __('Unassigned') }}</flux:text>
                        @endif

                        <flux:dropdown align="end" data-test="create-task-assignees">
                            <flux:button size="xs" variant="subtle" icon="plus" :aria-label="__('Edit assignees')" />
                            <flux:popover class="flex w-64 flex-col gap-2">
                                <flux:text size="xs" class="font-medium text-zinc-400">{{ __('Assignees') }}</flux:text>
                                <flux:select variant="listbox" multiple searchable wire:model.live="assigneeIds" :placeholder="__('Assign members')">
                                    @foreach ($this->members as $member)
                                        <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:popover>
                        </flux:dropdown>
                    </x-rail-row>
                @endif
            </flux:card>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="create-task-submit">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
