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
                        <flux:select.option value="">{{ __('None (top-level task)') }}</flux:select.option>
                        @foreach ($this->parentOptions as $id => $label)
                            <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            <flux:input
                wire:model="title"
                :label="__('Title')"
                data-test="create-task-title"
                x-on:create-task-focus-title.window="$nextTick(() => (($el.matches('input') ? $el : $el.querySelector('input'))?.focus()))"
            />

            <flux:editor wire:model="description" :label="__('Description')" data-test="create-task-description" />

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

            {{-- Tags, due date and assignees: compact label + value + one-click control --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {{-- Tags --}}
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Tags') }}</span>
                    <div class="flex min-h-8 flex-wrap items-center gap-1" data-test="create-task-tags">
                        @foreach ($tagNames as $index => $name)
                            <flux:badge size="sm" color="zinc" variant="pill" wire:key="draft-tag-{{ $index }}">
                                <x-tag-dot :color="$tagColors[$name] ?? 'zinc'" class="me-1.5 size-2" />{{ $name }}
                                <flux:badge.close wire:click="removeDraftTag({{ $index }})" :aria-label="__('Remove tag')" data-test="create-task-remove-tag-{{ $index }}" />
                            </flux:badge>
                        @endforeach

                        <flux:dropdown align="start">
                            <flux:button type="button" size="xs" variant="subtle" icon="plus" :aria-label="__('Add tag')" data-test="create-task-add-tag" />

                            <flux:popover class="flex w-64 flex-col gap-1">
                                <flux:input
                                    size="sm"
                                    wire:model.live.debounce.200ms="tagQuery"
                                    :placeholder="__('Find or create a tag')"
                                    x-on:keydown.enter.prevent="$wire.tagEnter($event.target.value)"
                                    data-test="create-task-tag-input"
                                />

                                @if (trim($tagQuery) !== '')
                                    <div class="flex max-h-48 flex-col gap-0.5 overflow-y-auto" role="listbox">
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
                                                wire:click="openTagColorModal"
                                                data-test="create-task-tag-create"
                                            >
                                                {{ __('Create') }} “{{ trim($tagQuery) }}”
                                            </flux:button>
                                        @endif
                                    </div>
                                @endif
                            </flux:popover>
                        </flux:dropdown>
                    </div>
                </div>

                {{-- Due date --}}
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Due date') }}</span>
                    <div class="flex min-h-8 items-center gap-1">
                        @if ($dueDate)
                            <flux:badge size="sm" color="zinc" variant="pill" data-test="create-task-due-date-badge">
                                {{ \Illuminate\Support\Carbon::parse($dueDate)->format('M j, Y') }}
                                <flux:badge.close wire:click="$set('dueDate', '')" :aria-label="__('Clear due date')" data-test="create-task-clear-due-date" />
                            </flux:badge>
                        @else
                            <flux:text size="sm" class="text-zinc-400">{{ __('None') }}</flux:text>
                        @endif

                        <flux:dropdown align="start">
                            <flux:button type="button" size="xs" variant="subtle" :icon="$dueDate ? 'pencil' : 'plus'" :aria-label="__('Set due date')" data-test="create-task-due-date-control" />
                            <flux:popover class="w-60">
                                <flux:input type="date" wire:model.live="dueDate" :label="__('Due date')" x-on:keydown.enter.prevent data-test="create-task-due-date" />
                            </flux:popover>
                        </flux:dropdown>
                    </div>
                </div>

                {{-- Assignees --}}
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Assignees') }}</span>
                    <div class="flex min-h-8 items-center">
                        @if ($this->projectId && $this->members->isNotEmpty())
                            <x-assignee-picker
                                :members="$this->members"
                                :selected="$this->members->whereIn('id', $assigneeIds)"
                                model="assigneeIds"
                            />
                        @else
                            <flux:text size="sm" class="text-zinc-400">{{ __('Unassigned') }}</flux:text>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5">
                    <flux:checkbox wire:model="createAnother" :label="__('Create another')" data-test="create-task-another" />
                    <flux:tooltip :content="__('Keeps the dialog open after saving so you can add more tasks in a row. The project, parent, priority and status carry over.')">
                        <flux:icon.question-mark-circle variant="micro" class="cursor-help text-zinc-400" tabindex="0" data-test="create-task-another-hint" />
                    </flux:tooltip>
                </div>

                <div class="flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="create-task-submit">{{ __('Create') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Choose a color for a brand-new tag --}}
    <flux:modal wire:model="showTagColorModal" class="md:w-96" data-test="create-task-tag-color-modal">
        <form wire:submit.prevent="confirmNewTag" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('New tag') }}</flux:heading>

            <flux:input wire:model="newTagName" :label="__('Name')" data-test="create-task-new-tag-name" />
            <flux:error name="newTagName" />

            <div class="flex flex-col gap-1.5">
                <flux:label>{{ __('Color') }}</flux:label>
                <div class="flex flex-wrap gap-2" data-test="create-task-tag-color-picker">
                    @foreach (\App\Models\Tag::PALETTE as $paletteColor)
                        <flux:button
                            type="button"
                            size="xs"
                            variant="ghost"
                            wire:click="$set('newTagColor', '{{ $paletteColor }}')"
                            @class([
                                'rounded-full! p-0! ring-2 ring-offset-2 ring-offset-white dark:ring-offset-zinc-800',
                                'ring-zinc-900 dark:ring-white' => $newTagColor === $paletteColor,
                                'ring-transparent' => $newTagColor !== $paletteColor,
                            ])
                            :aria-label="$paletteColor"
                            data-test="create-task-tag-color-{{ $paletteColor }}"
                        >
                            <x-tag-dot :color="$paletteColor" class="size-5" />
                        </flux:button>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-2">
                <flux:text size="sm" class="text-zinc-400">{{ __('Preview') }}</flux:text>
                <flux:badge size="sm" color="zinc" variant="pill">
                    <x-tag-dot :color="$newTagColor" class="me-1.5 size-2" />{{ $newTagName !== '' ? $newTagName : __('tag') }}
                </flux:badge>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="create-task-confirm-tag">{{ __('Add tag') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
