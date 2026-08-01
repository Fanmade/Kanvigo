<div class="app-content mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="project-task-types">
    {{-- Header --}}
    <x-project-settings-header :project="$this->project" :title="__('Task types')">
        <flux:button size="sm" variant="primary" icon="plus" wire:click="startCreate" data-test="new-task-type">
            {{ __('New type') }}
        </flux:button>
    </x-project-settings-header>

    <flux:text class="text-zinc-500">
        {{ __('Add, rename, recolor and reorder the types tasks can be classified with in this project.') }}
    </flux:text>

    @if ($this->taskTypes->isEmpty())
        <x-empty-state :heading="__('No task types yet')" test="task-types-empty">
            <flux:text
                size="sm"
                class="text-zinc-400"
            >{{ __('Add a type to classify this project\'s tasks.') }}</flux:text>
        </x-empty-state>
    @else
        <div
            class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10"
            data-test="task-types-list"
        >
            @foreach ($this->taskTypes as $type)
                <div
                    class="flex items-center justify-between gap-3 p-3"
                    wire:key="task-type-{{ $type->id }}"
                    data-test="task-type-row-{{ $type->id }}"
                >
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
                <x-color-picker :palette="$this->palette" :selected="$editColor" name="editColor" test="task-type" />
                <flux:error name="editColor" />
            </div>

            <div class="flex items-center gap-2">
                <flux:text size="sm" class="text-zinc-400">{{ __('Preview') }}</flux:text>
                @php($previewIcon = \App\Support\IconCatalog::validOrNull($editIcon))
                <flux:badge size="sm" :color="$editColor" :icon="$previewIcon">
                    {{ $editName !== '' ? $editName : __('Type') }}
                </flux:badge>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    type="submit"
                    variant="primary"
                    data-test="save-task-type"
                >{{ __('Save changes') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
