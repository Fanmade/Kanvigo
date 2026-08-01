{{-- Toggles whether the surrounding live view auto-refreshes. Backed by the
     HasLiveUpdates trait on the host Livewire component (persists the choice). --}}
<flux:switch wire:model.live="liveUpdates" :label="__('Live updates')" align="left" data-test="live-updates-toggle" />
