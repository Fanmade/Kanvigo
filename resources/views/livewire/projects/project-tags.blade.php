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
        {{ __('Rename, recolor and delete the tags used across this project.') }}
    </flux:text>

    @if ($this->tags->isEmpty())
        <div class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-zinc-200 p-10 text-center dark:border-white/10" data-test="tags-empty">
            <flux:icon icon="tag" class="size-8 text-zinc-300 dark:text-zinc-600" />
            <flux:heading size="sm">{{ __('No tags yet') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-400">{{ __('Tags appear here once they are added to tasks.') }}</flux:text>
        </div>
    @else
        <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10" data-test="tags-list">
            @foreach ($this->tags as $tag)
                <div class="flex items-center justify-between gap-3 p-3" wire:key="tag-{{ $tag->id }}" data-test="tag-row-{{ $tag->id }}">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:badge size="sm" color="zinc" variant="pill">
                            <x-tag-dot :color="$tag->color" class="me-1.5 size-2" />{{ $tag->name }}
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

            <div class="flex items-center gap-2">
                <flux:text size="sm" class="text-zinc-400">{{ __('Preview') }}</flux:text>
                <flux:badge size="sm" color="zinc" variant="pill">
                    <x-tag-dot :color="$editColor" class="me-1.5 size-2" />{{ $editName !== '' ? $editName : __('tag') }}
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
</div>
