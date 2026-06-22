{{-- "Close the parent too?" prompt. Shared by the task page and the boards; the
     surrounding Livewire component supplies the state via the PromptsParentClose
     trait. --}}
<flux:modal wire:model.self="confirmingParentClose" wire:close="dismissParentClose" class="md:w-96" data-test="parent-close-modal">
    <div class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Close the parent task too?') }}</flux:heading>
        <flux:text>
            {{ __('All subtasks of :reference are complete. Mark it done as well?', ['reference' => $parentCloseReference]) }}
        </flux:text>

        <flux:checkbox wire:model="rememberParentCloseChoice" :label="__('Remember my choice')" data-test="parent-close-remember" />

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="filled" wire:click="declineParentClose" data-test="parent-close-decline">
                {{ __('Leave it open') }}
            </flux:button>
            <flux:button type="button" variant="primary" wire:click="confirmParentClose" data-test="parent-close-confirm">
                {{ __('Mark it done') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
