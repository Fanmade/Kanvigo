<div class="app-content mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="project-variables">
    {{-- Header --}}
    <x-project-settings-header :project="$this->project" :title="__('Variables')">
        <flux:button size="sm" variant="primary" icon="plus" wire:click="startCreate" data-test="new-variable">
            {{ __('New variable') }}
        </flux:button>
    </x-project-settings-header>

    <flux:text class="text-zinc-500">
        {{ __('Named stand-ins for facts that appear in many places or are not decided yet. Write [name] in a description, comment or doc and it shows the value set here.') }}
    </flux:text>

    @if ($this->variables->isEmpty())
        <x-empty-state :heading="__('No variables yet')" test="variables-empty">
            <flux:text size="sm" class="text-zinc-400">{{ __('Create one, then use it as [name] anywhere in this project.') }}</flux:text>
            <flux:button size="sm" variant="primary" icon="plus" wire:click="startCreate" data-test="new-variable-empty" class="mt-1">
                {{ __('New variable') }}
            </flux:button>
        </x-empty-state>
    @else
        <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10" data-test="variables-list">
            @foreach ($this->variables as $variable)
                <div class="flex items-center justify-between gap-3 p-3" wire:key="variable-{{ $variable->id }}" data-test="variable-row-{{ $variable->id }}">
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <flux:text size="sm" class="font-mono text-zinc-500" data-test="variable-name-{{ $variable->id }}">
                                [{{ $variable->name }}]
                            </flux:text>
                            @if ($variable->isUnset())
                                <flux:badge size="sm" color="amber" data-test="variable-unset-{{ $variable->id }}">
                                    {{ __('No value yet') }}
                                </flux:badge>
                            @else
                                <flux:text class="min-w-0 truncate" data-test="variable-value-{{ $variable->id }}">
                                    {{ $variable->value }}
                                </flux:text>
                            @endif
                            @php($uses = $this->usageCounts[$variable->name] ?? 0)
                            <flux:text size="sm" class="text-zinc-400" data-test="variable-usage-{{ $variable->id }}">
                                {{ trans_choice('{0}Unused|{1}:count use|[2,*]:count uses', $uses, ['count' => $uses]) }}
                            </flux:text>
                        </div>
                        @if ($variable->description !== null)
                            <flux:text size="sm" class="text-zinc-400" data-test="variable-description-{{ $variable->id }}">
                                {{ $variable->description }}
                            </flux:text>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="pencil-square"
                            :aria-label="__('Edit variable')"
                            wire:click="startEdit({{ $variable->id }})"
                            data-test="edit-variable-{{ $variable->id }}"
                        />
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="trash"
                            :aria-label="__('Delete variable')"
                            wire:click="deleteVariable({{ $variable->id }})"
                            wire:confirm="{{ __('Delete this variable? The text keeps saying [name] everywhere it is used and starts showing as unset — recreating the variable brings the value back.') }}"
                            data-test="delete-variable-{{ $variable->id }}"
                        />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Names used in content that no variable defines. They render as unset, so
         listing them is how they get resolved rather than quietly lost. --}}
    @if ($this->unknownNames !== [])
        <div class="flex flex-col gap-3" data-test="unknown-names">
            <flux:heading size="lg">{{ __('Used but not defined') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('These names appear in this project’s text but have no variable, so they show as unset.') }}
            </flux:text>

            <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($this->unknownNames as $name => $uses)
                    <div class="flex items-center justify-between gap-3 p-3" wire:key="unknown-{{ $name }}" data-test="unknown-name-{{ $name }}">
                        <div class="flex min-w-0 items-center gap-2">
                            <flux:text size="sm" class="font-mono text-zinc-500">[{{ $name }}]</flux:text>
                            <flux:text size="sm" class="text-zinc-400">
                                {{ trans_choice('{1}:count use|[2,*]:count uses', $uses, ['count' => $uses]) }}
                            </flux:text>
                        </div>
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="plus"
                            wire:click="startCreate('{{ $name }}')"
                            data-test="define-{{ $name }}"
                        >
                            {{ __('Define') }}
                        </flux:button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Create / edit modal --}}
    <flux:modal wire:model="editing" class="md:w-96" data-test="edit-variable-modal">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingVariableId === null ? __('New variable') : __('Edit variable') }}</flux:heading>

            <flux:input
                wire:model="editName"
                :label="__('Name')"
                :description="__('Used as [name] in text. Lowercase letters, digits, underscores and hyphens.')"
                data-test="edit-variable-name"
            />
            <flux:error name="editName" />

            <flux:input
                wire:model="editValue"
                :label="__('Value')"
                :description="__('What it stands for. Leave empty while it is still undecided.')"
                data-test="edit-variable-value"
            />
            <flux:error name="editValue" />

            <flux:input
                wire:model="editDescription"
                :label="__('Description')"
                :description="__('Optional note on what this variable is for.')"
                data-test="edit-variable-description"
            />
            <flux:error name="editDescription" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-variable">
                    {{ $editingVariableId === null ? __('Create') : __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
