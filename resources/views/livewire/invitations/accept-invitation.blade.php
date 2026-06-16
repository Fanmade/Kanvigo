<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Accept your invitation')"
        :description="__('Choose a username and set a password to create your account')"
    />

    <form wire:submit="accept" class="flex flex-col gap-6">
        <flux:input
            :label="__('Email address')"
            type="email"
            :value="$invitation->email"
            readonly
            disabled
        />

        <flux:input
            wire:model="name"
            :label="__('Username')"
            type="text"
            required
            autofocus
            autocomplete="username"
        />

        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            viewable
            autocomplete="new-password"
        />

        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            viewable
            autocomplete="new-password"
        />

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Create account') }}
        </flux:button>
    </form>
</div>
