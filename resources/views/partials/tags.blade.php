@php($canManageTags = $this->canManageTags)

<div class="flex flex-col gap-3" data-test="tags">
    <div class="flex items-center justify-between gap-2">
        <flux:heading size="sm">{{ __('Tags') }}</flux:heading>
        @if ($canManageTags)
            <flux:button
                size="xs"
                variant="subtle"
                icon="plus"
                x-on:click="$dispatch('open-tag-input')"
                data-test="toggle-add-tag"
            >
                {{ __('Add') }}
            </flux:button>
        @endif
    </div>

    @if ($this->appliedTags->isNotEmpty())
        <div class="flex flex-wrap gap-1">
            @foreach ($this->appliedTags as $tag)
                <flux:badge size="sm" color="zinc" variant="pill" wire:key="applied-tag-{{ $tag->id }}">
                    <x-tag-dot :color="$tag->color" :icon="$tag->icon" class="me-1.5" />{{ $tag->name }}
                    @if ($canManageTags)
                        <flux:badge.close
                            wire:click="removeTag({{ $tag->id }})"
                            :aria-label="__('Remove tag')"
                            data-test="remove-tag-{{ $tag->id }}"
                        />
                    @endif
                </flux:badge>
            @endforeach
        </div>
    @else
        <flux:text size="sm" class="text-zinc-400">{{ __('No tags yet.') }}</flux:text>
    @endif

    @if ($canManageTags)
        <div
            x-data="tagInput({ suggestions: @js($this->tagSuggestions->all()), createPrefix: @js(__('Create')) })"
            x-on:tags-updated.window="suggestions = $event.detail.suggestions; reset()"
            x-on:open-tag-input.window="open()"
            x-show="adding"
            x-cloak
            class="flex flex-col gap-1.5"
            data-test="tag-input"
        >
            <flux:input
                size="sm"
                x-ref="input"
                x-model="query"
                x-on:keydown.down.prevent="move(1)"
                x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.enter.prevent="choose()"
                x-on:keydown.escape="adding = false"
                :placeholder="__('Find or create a tag')"
                data-test="tag-input-field"
            />

            <div class="flex flex-col gap-0.5" role="listbox">
                <template x-for="(tag, index) in filtered()" :key="tag.name">
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded px-2 py-1 text-start text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700"
                        :class="index === highlighted ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
                        x-on:mouseenter="highlighted = index"
                        x-on:click="add(tag.name)"
                        x-bind:data-test="'tag-suggestion-' + tag.name"
                    >
                        <span class="inline-block size-2 shrink-0 rounded-full" :class="dotClass(tag.color)"></span>
                        <span x-text="tag.name"></span>
                    </button>
                </template>

                <button
                    type="button"
                    x-show="canCreate()"
                    class="flex items-center gap-2 rounded px-2 py-1 text-start text-sm text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700"
                    :class="highlighted === filtered().length ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
                    x-on:mouseenter="highlighted = filtered().length"
                    x-on:click="createNew()"
                    data-test="tag-input-create"
                >
                    <flux:icon icon="plus" variant="micro" class="size-4 shrink-0" />
                    <span x-text="createLabel()"></span>
                </button>
            </div>
        </div>

        {{-- Create-tag modal --}}
        <flux:modal wire:model="showTagModal" class="md:w-96" data-test="create-tag-modal">
            <form wire:submit="createTag" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('New tag') }}</flux:heading>

                <flux:input
                    wire:model.live.debounce.300ms="newTagName"
                    :label="__('Name')"
                    data-test="new-tag-name"
                />
                <flux:error name="newTagName" />

                <div class="flex flex-col gap-1.5">
                    <flux:label>{{ __('Color') }}</flux:label>
                    <div class="flex flex-wrap gap-2" data-test="tag-color-picker">
                        @foreach (\App\Models\Tag::PALETTE as $paletteColor)
                            <button
                                type="button"
                                wire:click="$set('newTagColor', '{{ $paletteColor }}')"
                                @class([
                                    'flex size-7 cursor-pointer items-center justify-center rounded-full ring-2 ring-offset-2 ring-offset-white dark:ring-offset-zinc-800',
                                    'ring-zinc-900 dark:ring-white' => $newTagColor === $paletteColor,
                                    'ring-transparent' => $newTagColor !== $paletteColor,
                                ])
                                aria-label="{{ $paletteColor }}"
                                data-test="tag-color-{{ $paletteColor }}"
                            >
                                <x-tag-dot :color="$paletteColor" class="size-5" />
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="newTagColor" />
                </div>

                <x-icon-picker name="newTagIcon" :selected="$newTagIcon" test="tag" />

                @php($previewIcon = in_array($newTagIcon, \App\Models\TaskType::ICONS, true) ? $newTagIcon : null)
                <div class="flex items-center gap-2">
                    <flux:text size="sm" class="text-zinc-400">{{ __('Preview') }}</flux:text>
                    <flux:badge size="sm" color="zinc" variant="pill">
                        <x-tag-dot :color="$newTagColor" :icon="$previewIcon" class="me-1.5" />{{ $newTagName !== '' ? $newTagName : __('tag') }}
                    </flux:badge>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="create-tag">{{ __('Create tag') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
