{{-- Toggles whether the surrounding live view auto-refreshes. Backed by the
     HasLiveUpdates trait on the host Livewire component (persists the choice).

     An icon rather than a labelled switch: it sits in header toolbars beside
     other icon buttons, where a switch and its label pushed the row out of
     shape on narrow screens. The state is carried by the icon itself — a
     pulsing signal while live, a struck-through one while paused. --}}
@props(['liveUpdates'])

<div>
    @if ($liveUpdates)
        <flux:tooltip :content="__('Live updates on — click to pause')">
            <flux:button
                size="sm"
                variant="ghost"
                icon="signal"
                icon:class="animate-pulse"
                wire:click="toggleLiveUpdates"
                class="text-indigo-500 dark:text-indigo-400"
                :aria-label="__('Pause live updates')"
                data-test="live-updates-toggle"
            />
        </flux:tooltip>
    @else
        <flux:tooltip :content="__('Live updates off — click to enable')">
            <flux:button
                size="sm"
                variant="ghost"
                icon="signal-slash"
                wire:click="toggleLiveUpdates"
                class="text-zinc-400"
                :aria-label="__('Enable live updates')"
                data-test="live-updates-toggle"
            />
        </flux:tooltip>
    @endif
</div>
