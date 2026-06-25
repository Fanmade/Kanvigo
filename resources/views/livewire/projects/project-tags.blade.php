<div class="app-content mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="project-tags">
    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <div class="flex min-w-0 items-center gap-3">
            <flux:button
                size="sm"
                variant="ghost"
                icon="arrow-left"
                :href="route('project.show', $this->project)"
                :aria-label="__('Back to project')"
                wire:navigate
                data-test="back-to-project"
            />
            <flux:heading size="xl" class="min-w-0 truncate">{{ __('Tags') }}</flux:heading>
            <flux:badge color="indigo">{{ $this->project->short_name }}</flux:badge>
        </div>
    </div>

    <flux:text class="text-zinc-500">
        {{ __('Rename, recolor, merge and delete the tags used across this project.') }}
    </flux:text>

    @if ($this->tags->isEmpty())
        <div class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-zinc-200 p-10 text-center dark:border-white/10" data-test="tags-empty">
            <flux:icon icon="tag" class="size-8 text-zinc-300 dark:text-zinc-600" />
            <flux:heading size="sm">{{ __('No tags yet') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-400">{{ __('Tags appear here once they are added to tasks.') }}</flux:text>
        </div>
    @else
        <div class="relative">
            {{--
                Selection action bar. Pinned just above the table (bottom-full) and
                taken out of the document flow (absolute), so showing or hiding it
                never moves the table. Centered over the table. Driven by Alpine off
                the live `selected` array, so it reacts the instant a checkbox
                changes — no server round-trip to show it.
            --}}
            @can('manageSettings', $this->project)
                <div
                    x-data
                    x-show="$wire.selected.length > 0"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="translate-y-1 opacity-0"
                    x-transition:enter-end="translate-y-0 opacity-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="translate-y-0 opacity-100"
                    x-transition:leave-end="translate-y-1 opacity-0"
                    class="absolute bottom-full left-1/2 z-10 mb-2 -translate-x-1/2"
                    data-test="tags-merge-toolbar"
                >
                    <div class="flex items-center gap-3 rounded-full border border-zinc-200 bg-white py-1.5 ps-4 pe-2 shadow-lg dark:border-white/10 dark:bg-zinc-800">
                        <flux:text size="sm">
                            <span x-text="$wire.selected.length"></span> {{ __('selected') }}
                        </flux:text>
                        <flux:button size="sm" variant="ghost" x-on:click="$wire.selected = []" data-test="clear-tag-selection">
                            {{ __('Clear') }}
                        </flux:button>
                        <flux:button
                            size="sm"
                            variant="primary"
                            icon="arrows-pointing-in"
                            wire:click="startMerge"
                            x-bind:disabled="$wire.selected.length < 2"
                            data-test="merge-tags"
                        >
                            {{ __('Merge') }}
                        </flux:button>
                    </div>
                </div>
            @endcan

            <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10" data-test="tags-list">
            @foreach ($this->tags as $tag)
                <div class="flex items-center justify-between gap-3 p-3" wire:key="tag-{{ $tag->id }}" data-test="tag-row-{{ $tag->id }}">
                    <div class="flex min-w-0 items-center gap-3">
                        @can('manageSettings', $this->project)
                            <flux:checkbox
                                wire:model="selected"
                                value="{{ $tag->id }}"
                                :aria-label="__('Select :name', ['name' => $tag->name])"
                                data-test="select-tag-{{ $tag->id }}"
                            />
                        @endcan
                        <flux:badge size="sm" color="zinc" variant="pill">
                            <x-tag-dot :color="$tag->color" :icon="$tag->icon" class="me-1.5" />{{ $tag->name }}
                        </flux:badge>
                        <flux:text size="sm" class="text-zinc-400" data-test="tag-usage-{{ $tag->id }}">
                            {{ trans_choice('{0}Unused|{1}:count task|[2,*]:count tasks', $tag->tasks_count, ['count' => $tag->tasks_count]) }}
                        </flux:text>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="pencil-square"
                            :aria-label="__('Edit tag')"
                            wire:click="startEdit({{ $tag->id }})"
                            data-test="edit-tag-{{ $tag->id }}"
                        />
                        @can('manageSettings', $this->project)
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="trash"
                                :aria-label="__('Delete tag')"
                                wire:click="deleteTag({{ $tag->id }})"
                                wire:confirm="{{ __('Delete this tag? It will be removed from every task.') }}"
                                data-test="delete-tag-{{ $tag->id }}"
                            />
                        @endcan
                    </div>
                </div>
            @endforeach
            </div>
        </div>
    @endif

    {{-- Edit-tag modal --}}
    <flux:modal wire:model="editing" class="md:w-96" data-test="edit-tag-modal">
        <form wire:submit="saveEdit" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Edit tag') }}</flux:heading>

            <flux:input
                wire:model.live.debounce.300ms="editName"
                :label="__('Name')"
                data-test="edit-tag-name"
            />
            <flux:error name="editName" />

            <div class="flex flex-col gap-1.5">
                <flux:label>{{ __('Color') }}</flux:label>
                <div class="flex flex-wrap gap-2" data-test="edit-tag-color-picker">
                    @foreach ($this->palette as $paletteColor)
                        <button
                            type="button"
                            wire:click="$set('editColor', '{{ $paletteColor }}')"
                            @class([
                                'flex size-7 cursor-pointer items-center justify-center rounded-full ring-2 ring-offset-2 ring-offset-white dark:ring-offset-zinc-800',
                                'ring-zinc-900 dark:ring-white' => $editColor === $paletteColor,
                                'ring-transparent' => $editColor !== $paletteColor,
                            ])
                            aria-label="{{ $paletteColor }}"
                            data-test="edit-tag-color-{{ $paletteColor }}"
                        >
                            <x-tag-dot :color="$paletteColor" class="size-5" />
                        </button>
                    @endforeach
                </div>
                <flux:error name="editColor" />
            </div>

            <x-icon-picker name="editIcon" :selected="$editIcon" test="edit-tag" clear="clearIcon" />

            @php($previewIcon = in_array($editIcon, \App\Models\TaskType::ICONS, true) ? $editIcon : null)
            <div class="flex items-center gap-2">
                <flux:text size="sm" class="text-zinc-400">{{ __('Preview') }}</flux:text>
                <flux:badge size="sm" color="zinc" variant="pill">
                    <x-tag-dot :color="$editColor" :icon="$previewIcon" class="me-1.5" />{{ $editName !== '' ? $editName : __('tag') }}
                </flux:badge>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-tag">{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Merge-tags modal --}}
    <flux:modal wire:model="merging" class="md:w-96" data-test="merge-tags-modal">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Merge tags') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('Pick the tag to keep. Tasks tagged with the others are re-tagged with it, then the others are deleted.') }}
            </flux:text>

            <flux:radio.group wire:model="mergeTargetId" variant="cards" class="flex flex-col gap-2" data-test="merge-target-group">
                @foreach ($this->selectedTags as $tag)
                    <flux:radio value="{{ $tag->id }}" :label="$tag->name" data-test="merge-target-{{ $tag->id }}">
                        <span class="flex items-center gap-2">
                            <flux:badge size="sm" color="zinc" variant="pill">
                                <x-tag-dot :color="$tag->color" :icon="$tag->icon" class="me-1.5" />{{ $tag->name }}
                            </flux:badge>
                            <flux:text size="sm" class="text-zinc-400">
                                {{ trans_choice('{0}Unused|{1}:count task|[2,*]:count tasks', $tag->tasks_count, ['count' => $tag->tasks_count]) }}
                            </flux:text>
                        </span>
                    </flux:radio>
                @endforeach
            </flux:radio.group>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="mergeTags" data-test="confirm-merge">{{ __('Merge tags') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
