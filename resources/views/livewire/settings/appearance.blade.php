<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-settings.layout :heading="__('Appearance')" :subheading=" __('Update the appearance settings for your account')">
        <div class="flex flex-col gap-6">
            <flux:field>
                <flux:label>{{ __('Theme') }}</flux:label>
                <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
                    <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                    <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                    <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
                </flux:radio.group>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Language') }}</flux:label>
                <flux:radio.group variant="segmented" wire:model.live="locale">
                    <flux:radio value="en" icon="language">{{ __('English') }}</flux:radio>
                    <flux:radio value="de" icon="language">{{ __('German') }}</flux:radio>
                </flux:radio.group>
            </flux:field>

            <flux:field variant="inline">
                <flux:switch wire:model.live="fullWidth" data-test="full-width-toggle" />
                <flux:label>{{ __('Full-width layout') }}</flux:label>
                <flux:description>{{ __('Use the entire screen width instead of a centered column. Best on large displays.') }}</flux:description>
            </flux:field>
        </div>
    </x-settings.layout>
</section>
