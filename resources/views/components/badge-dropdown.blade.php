@props(['model', 'test'])

{{--
    A badge that opens a single-select radio menu bound to a Livewire property —
    the shared shell behind the priority/status/task-type controls. The clickable
    badge goes in the "trigger" slot; the default slot holds the <flux:menu.radio>
    options. The chosen value is written to $model via wire:model.live.
--}}
<flux:dropdown align="end" data-test="{{ $test }}">
    {{ $trigger }}

    <flux:menu>
        <flux:menu.radio.group wire:model.live="{{ $model }}"> {{ $slot }} </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
