{{-- The editor's "Create variable…" dialog. Rendered once per page; the picker
     inserts the usage where it was asked for once the variable exists. --}}
<div>
    <flux:modal wire:model="open" class="md:w-96" data-test="create-variable-modal">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('New variable') }}</flux:heading>

            <flux:input
                wire:model="name"
                :label="__('Name')"
                :description="__('Used as [name] in text. Lowercase letters, digits, underscores and hyphens.')"
                data-test="create-variable-name"
            />
            <flux:error name="name" />

            <flux:input
                wire:model="value"
                :label="__('Value')"
                :description="__('What it stands for. Leave empty while it is still undecided.')"
                data-test="create-variable-value"
            />
            <flux:error name="value" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-new-variable">
                    {{ __('Create') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
