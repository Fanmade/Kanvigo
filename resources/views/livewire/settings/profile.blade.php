<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <div class="my-6 flex items-center gap-4" data-test="avatar-section">
            <flux:avatar
                size="xl"
                :src="$avatar?->isPreviewable() ? $avatar->temporaryUrl() : $this->avatarUrl"
                :name="$name"
                :initials="auth()->user()->initials()"
                data-test="avatar-preview"
            />

            <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2" x-data>
                    <flux:button
                        size="sm"
                        variant="filled"
                        x-on:click="$refs.avatarInput.click()"
                        data-test="avatar-upload"
                    >
                        <span wire:loading.remove wire:target="avatar">{{ __('Change') }}</span>
                        <span wire:loading wire:target="avatar">{{ __('Uploading…') }}</span>
                    </flux:button>

                    <input
                        type="file"
                        x-ref="avatarInput"
                        wire:model="avatar"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        class="hidden"
                        data-test="avatar-input"
                    />

                    @if (auth()->user()->hasAvatar())
                        <flux:button size="sm" variant="ghost" wire:click="removeAvatar" data-test="avatar-remove">
                            {{ __('Remove') }}
                        </flux:button>
                    @endif
                </div>

                <flux:error name="avatar" />
                <flux:text size="sm" class="text-zinc-500">{{ __('JPG, PNG, WEBP or GIF up to 4 MB.') }}</flux:text>
            </div>
        </div>

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
