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

            {{-- Tags --}}
            <div x-data="{ adding: false }" class="flex flex-col gap-2">
                <div class="flex min-h-7 items-center justify-between gap-3">
                    <span class="shrink-0 text-sm font-medium text-zinc-800 dark:text-white">{{ __('Tags') }}</span>
                    <div class="flex min-w-0 flex-wrap items-center justify-end gap-1" data-test="create-task-tags">
                        @foreach ($tagNames as $index => $name)
                            <flux:badge size="sm" color="zinc" variant="pill" wire:key="draft-tag-{{ $index }}">
                                <x-tag-dot :color="$tagColors[$name] ?? 'zinc'" class="me-1.5 size-2" />{{ $name }}
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
                        x-on:keydown.enter.prevent="$wire.tagEnter($event.target.value)"
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
                                    wire:click="openTagColorModal"
                                    data-test="create-task-tag-create"
                                >
                                    {{ __('Create') }} “{{ trim($tagQuery) }}”
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Due date + assignees --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input type="date" wire:model="dueDate" :label="__('Due date')" data-test="create-task-due-date" />

                @if ($this->projectId && $this->members->isNotEmpty())
                    <flux:select variant="listbox" multiple searchable wire:model="assigneeIds" :label="__('Assignees')" :placeholder="__('Select assignees')" data-test="create-task-assignees">
                        @foreach ($this->members as $member)
                            <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="create-task-submit">{{ __('Create') }}</flux:button>
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
