<div class="app-content mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="project-task-types">
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
            <flux:heading size="xl" class="min-w-0 truncate">{{ __('Task types') }}</flux:heading>
            <flux:badge color="indigo">{{ $this->project->short_name }}</flux:badge>
        </div>

        <flux:button size="sm" variant="primary" icon="plus" wire:click="startCreate" data-test="new-task-type">
            {{ __('New type') }}
        </flux:button>
    </div>

    <flux:text class="text-zinc-500">
        {{ __('Add, rename, recolor and reorder the types tasks can be classified with in this project.') }}
    </flux:text>

    @if ($this->taskTypes->isEmpty())
        <div class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-zinc-200 p-10 text-center dark:border-white/10" data-test="task-types-empty">
            <flux:icon icon="tag" class="size-8 text-zinc-300 dark:text-zinc-600" />
            <flux:heading size="sm">{{ __('No task types yet') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-400">{{ __('Add a type to classify this project\'s tasks.') }}</flux:text>
        </div>
    @else
        <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10" data-test="task-types-list">
            @foreach ($this->taskTypes as $type)
                <div class="flex items-center justify-between gap-3 p-3" wire:key="task-type-{{ $type->id }}" data-test="task-type-row-{{ $type->id }}">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex flex-col">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="chevron-up"
                                :aria-label="__('Move up')"
                                wire:click="moveUp({{ $type->id }})"
                                :disabled="$loop->first"
                                data-test="move-up-{{ $type->id }}"
                            />
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="chevron-down"
                                :aria-label="__('Move down')"
                                wire:click="moveDown({{ $type->id }})"
                                :disabled="$loop->last"
                                data-test="move-down-{{ $type->id }}"
                            />
                        </div>

                        <x-task-type-badge :type="$type" />

                        @if ($type->branch_prefix)
                            <flux:text size="sm" class="font-mono text-zinc-400">{{ $type->branch_prefix }}</flux:text>
                        @endif

                        <flux:text size="sm" class="text-zinc-400" data-test="task-type-usage-{{ $type->id }}">
                            {{ trans_choice('{0}Unused|{1}:count task|[2,*]:count tasks', $type->tasks_count, ['count' => $type->tasks_count]) }}
                        </flux:text>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="pencil-square"
                            :aria-label="__('Edit type')"
                            wire:click="startEdit({{ $type->id }})"
                            data-test="edit-task-type-{{ $type->id }}"
                        />
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="trash"
                            :aria-label="__('Delete type')"
                            wire:click="deleteType({{ $type->id }})"
                            wire:confirm="{{ __('Delete this type? Its tasks will keep their data but become untyped.') }}"
                            data-test="delete-task-type-{{ $type->id }}"
                        />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Create / edit modal --}}
    <flux:modal wire:model="editing" class="md:w-96" data-test="edit-task-type-modal">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingTypeId ? __('Edit type') : __('New type') }}</flux:heading>

            <flux:input wire:model="editName" :label="__('Name')" data-test="task-type-name" />
            <flux:error name="editName" />

            <flux:input
                wire:model="editBranchPrefix"
                :label="__('Branch prefix')"
                :description="__('Used for git branch names, e.g. feat or bugfix. Optional.')"
                data-test="task-type-branch-prefix"
            />
            <flux:error name="editBranchPrefix" />

            <x-icon-picker name="editIcon" :selected="$editIcon" test="task-type" clear="clearIcon" />

            <div class="flex flex-col gap-1.5">
                <flux:label>{{ __('Color') }}</flux:label>
                <div class="flex flex-wrap gap-2" data-test="task-type-color-picker">
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
                            data-test="task-type-color-{{ $paletteColor }}"
                        >
                            <x-tag-dot :color="$paletteColor" class="size-5" />
                        </button>
                    @endforeach
                </div>
                <flux:error name="editColor" />
            </div>

            <div class="flex items-center gap-2">
                <flux:text size="sm" class="text-zinc-400">{{ __('Preview') }}</flux:text>
                @php($previewIcon = in_array($editIcon, \App\Models\TaskType::ICONS, true) ? $editIcon : null)
                <flux:badge size="sm" :color="$editColor" :icon="$previewIcon">
                    {{ $editName !== '' ? $editName : __('Type') }}
                </flux:badge>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-task-type">{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
