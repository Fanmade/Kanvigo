<div class="flex flex-col gap-6" data-test="delivery-settings">
    @unless ($this->isVerified())
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="delivery-unverified">
            <flux:callout.heading>{{ __('Your e-mail address is not confirmed') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Kanvigo only sends to confirmed addresses, so nothing will arrive until yours is.') }}
            </flux:callout.text>
        </flux:callout>
    @endunless

    <flux:field variant="inline">
        <flux:switch wire:model.live="email" data-test="email-toggle" />
        <flux:label>{{ __('E-mail updates') }}</flux:label>
        <flux:description>
            {{ __('Also send the notifications you already receive in the app to your e-mail address. In-app notifications are unaffected — unfollow an item to stop those.') }}
        </flux:description>
    </flux:field>

    {{-- The interval and the per-level switches only mean anything once e-mail is
         on; they stay
         visible while it is off so the shape of the setting is obvious, but read
         as inactive. --}}
    <div
        @class(['flex flex-col gap-4 ps-2', 'pointer-events-none opacity-50' => ! $email])
        @if ($email) data-test="delivery-levels-active" @endif
    >
        <flux:field>
            <flux:label>{{ __('How often') }}</flux:label>
            <flux:radio.group wire:model.live="mode" variant="segmented" data-test="delivery-mode">
                @foreach ($this->modes() as $mode)
                    <flux:radio
                        :value="$mode->value"
                        :disabled="! $email"
                        data-test="delivery-mode-{{ $mode->value }}"
                    >{{ $mode->label() }}</flux:radio>
                @endforeach
            </flux:radio.group>
            <flux:description>
                {{ __('A digest collects what you have not read yet into a single mail instead of sending one per update.') }}
            </flux:description>
        </flux:field>

        <flux:field variant="inline">
            <flux:switch wire:model.live="emailTasks" :disabled="! $email" data-test="email-tasks-toggle" />
            <flux:label>{{ __('Tasks') }}</flux:label>
            <flux:description>{{ __('Changes to tasks you follow.') }}</flux:description>
        </flux:field>

        <flux:field variant="inline">
            <flux:switch wire:model.live="emailProjects" :disabled="! $email" data-test="email-projects-toggle" />
            <flux:label>{{ __('Projects') }}</flux:label>
            <flux:description>{{ __('Changes to projects you follow.') }}</flux:description>
        </flux:field>
    </div>

    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
        {{ __('Mentions are only shown in the app for now.') }}
    </flux:text>
</div>
