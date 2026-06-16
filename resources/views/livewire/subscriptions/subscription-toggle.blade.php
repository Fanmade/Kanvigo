<div>
    @if ($this->subscribed)
        <flux:tooltip :content="__('Notifications on — click to disable')">
            <flux:button
                size="sm"
                variant="ghost"
                icon="bell"
                wire:click="toggle"
                class="text-indigo-500 dark:text-indigo-400"
                :aria-label="__('Disable notifications')"
            />
        </flux:tooltip>
    @else
        <flux:tooltip :content="__('Notifications off — click to enable')">
            <flux:button
                size="sm"
                variant="ghost"
                icon="bell-slash"
                wire:click="toggle"
                class="text-zinc-400"
                :aria-label="__('Enable notifications')"
            />
        </flux:tooltip>
    @endif
</div>
