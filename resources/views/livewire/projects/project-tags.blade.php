<div class="app-content mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="project-tags">
    {{-- Header --}}
    <x-project-settings-header :project="$this->project" :title="__('Tags')">
        <flux:button size="sm" variant="primary" icon="plus" wire:click="startCreate" data-test="new-tag">
            {{ __('New tag') }}
        </flux:button>
    </x-project-settings-header>

    <flux:text class="text-zinc-500">
        {{ __('Rename, recolor, merge and delete the tags used across this project.') }}
    </flux:text>

    @if ($this->tags->isEmpty())
        <x-empty-state :heading="__('No tags yet')" test="tags-empty">
            <flux:text
                size="sm"
                class="text-zinc-400"
            >{{ __('Tags appear here once they are added to tasks, or create one now.') }}</flux:text>
            <flux:button
                size="sm"
                variant="primary"
                icon="plus"
                wire:click="startCreate"
                data-test="new-tag-empty"
                class="mt-1"
            >
                {{ __('New tag') }}
            </flux:button>
        </x-empty-state>
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
                        <flux:button
                            size="sm"
                            variant="ghost"
                            x-on:click="$wire.selected = []"
                            data-test="clear-tag-selection"
                        >
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

            <div
                class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10"
                data-test="tags-list"
            >
                @foreach ($this->tags as $tag)
                    <div
                        class="flex items-center justify-between gap-3 p-3"
                        wire:key="tag-{{ $tag->id }}"
                        data-test="tag-row-{{ $tag->id }}"
                    >
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
            <flux:heading size="lg">{{ $editingTagId === null ? __('New tag') : __('Edit tag') }}</flux:heading>

            <flux:input wire:model.live.debounce.300ms="editName" :label="__('Name')" data-test="edit-tag-name" />
            <flux:error name="editName" />

            <div class="flex flex-col gap-1.5">
                <flux:label>{{ __('Color') }}</flux:label>
                <x-color-picker :palette="$this->palette" :selected="$editColor" name="editColor" test="edit-tag" />
                <flux:error name="editColor" />
            </div>

            <x-icon-picker name="editIcon" :selected="$editIcon" test="edit-tag" clear="clearIcon" />

            @php($previewIcon = \App\Support\IconCatalog::validOrNull($editIcon))
            <div class="flex items-center gap-2">
                <flux:text size="sm" class="text-zinc-400">{{ __('Preview') }}</flux:text>
                <flux:badge size="sm" color="zinc" variant="pill">
                    <x-tag-dot
                        :color="$editColor"
                        :icon="$previewIcon"
                        class="me-1.5"
                    />{{ $editName !== '' ? $editName : __('tag') }}
                </flux:badge>
            </div>

            <div class="flex flex-col gap-1.5">
                <flux:label>{{ __('Synonyms') }}</flux:label>
                <flux:text size="sm" class="text-zinc-400">
                    {{ __('Alternative names this tag is also found by when searching.') }}
                </flux:text>
                @if ($editSynonyms !== [])
                    <div class="flex flex-wrap items-center gap-1" data-test="edit-tag-synonyms">
                        @foreach ($editSynonyms as $index => $synonym)
                            <flux:badge size="sm" color="zinc" variant="pill" wire:key="synonym-{{ $index }}">
                                {{ $synonym }}
                                <flux:badge.close
                                    wire:click="removeSynonym({{ $index }})"
                                    :aria-label="__('Remove synonym')"
                                    data-test="remove-synonym-{{ $index }}"
                                />
                            </flux:badge>
                        @endforeach
                    </div>
                @endif
                <flux:input
                    size="sm"
                    wire:model="synonymQuery"
                    :placeholder="__('Add a synonym')"
                    x-on:keydown.enter.prevent="$wire.addSynonym()"
                    data-test="edit-tag-synonym-input"
                />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    type="submit"
                    variant="primary"
                    data-test="save-tag"
                >{{ $editingTagId === null ? __('Create') : __('Save changes') }}</flux:button>
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

            <flux:radio.group
                wire:model="mergeTargetId"
                variant="cards"
                class="flex flex-col gap-2"
                data-test="merge-target-group"
            >
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

            <flux:checkbox
                wire:model="mergeAsSynonyms"
                :label="__('Keep the merged tags\' names as synonyms of the surviving tag')"
                data-test="merge-as-synonyms"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="primary"
                    wire:click="mergeTags"
                    data-test="confirm-merge"
                >{{ __('Merge tags') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
