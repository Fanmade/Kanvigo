<div class="app-content mx-auto flex w-full max-w-3xl flex-col gap-6" data-test="notifications-page">
    <div>
        <flux:heading size="xl">{{ __('Notifications') }}</flux:heading>
        <flux:subheading>{{ __('Everything you have received, and the items you receive it from.') }}</flux:subheading>
    </div>

    <flux:tab.group>
        <flux:tabs wire:model.live="tab" variant="segmented">
            <flux:tab name="inbox" icon="inbox" data-test="tab-inbox">{{ __('Inbox') }}</flux:tab>
            <flux:tab
                name="subscriptions"
                icon="bell"
                data-test="tab-subscriptions"
            >{{ __('Subscriptions') }}</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="inbox">
            <livewire:notifications.notification-inbox />
        </flux:tab.panel>

        <flux:tab.panel name="subscriptions">
            <livewire:notifications.subscription-settings />
        </flux:tab.panel>
    </flux:tab.group>
</div>
