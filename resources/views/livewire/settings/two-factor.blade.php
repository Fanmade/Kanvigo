<div>
    <section class="mt-12">
        <flux:heading>{{ __('Two-factor authentication') }}</flux:heading>
        <flux:subheading>{{ __('Manage your two-factor authentication settings') }}</flux:subheading>

        <div class="mx-auto flex w-full flex-col space-y-6 text-sm" wire:cloak>
            @if ($twoFactorEnabled)
                <div class="space-y-4">
                    <flux:text>
                        {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                    </flux:text>

                    <div class="flex justify-start">
                        <flux:button variant="danger" wire:click="disable"> {{ __('Disable 2FA') }} </flux:button>
                    </div>

                    <livewire:settings.two-factor.recovery-codes :$requiresConfirmation />
                </div>
            @else
                <div class="space-y-4">
                    <flux:text variant="subtle">
                        {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                    </flux:text>

                    <flux:button variant="primary" wire:click="enable"> {{ __('Enable 2FA') }} </flux:button>
                </div>
            @endif
        </div>
    </section>

    <flux:modal name="two-factor-setup-modal" class="max-w-md md:min-w-md" @close="closeModal" wire:model="showModal">
        <div class="space-y-6">
            <div class="flex flex-col items-center space-y-4">
                <div class="w-auto rounded-full border border-zinc-100 bg-white p-0.5 shadow-sm dark:border-zinc-600 dark:bg-zinc-800">
                    <div class="relative overflow-hidden rounded-full border border-zinc-200 bg-zinc-100 p-2.5 dark:border-zinc-600 dark:bg-zinc-200">
                        <div class="[&>div]:flex-1 absolute inset-0 flex h-full w-full items-stretch justify-around divide-x divide-zinc-200 opacity-50 dark:divide-zinc-300">
                            @for ($i = 1; $i <= 5; $i++)
                                <div></div>
                            @endfor
                        </div>

                        <div class="[&>div]:flex-1 absolute inset-0 flex h-full w-full flex-col items-stretch justify-around divide-y divide-zinc-200 opacity-50 dark:divide-zinc-300">
                            @for ($i = 1; $i <= 5; $i++)
                                <div></div>
                            @endfor
                        </div>

                        <flux:icon.qr-code class="dark:text-accent-foreground relative z-20" />
                    </div>
                </div>

                <div class="space-y-2 text-center">
                    <flux:heading size="lg">{{ $this->modalConfig['title'] }}</flux:heading>
                    <flux:text>{{ $this->modalConfig['description'] }}</flux:text>
                </div>
            </div>

            @if ($showVerificationStep)
                <div class="space-y-6">
                    <div
                        class="flex flex-col items-center justify-center space-y-3"
                        x-data
                        x-init="$nextTick(() => $el.querySelector('input')?.focus())"
                    >
                        <flux:otp
                            name="code"
                            wire:model="code"
                            length="6"
                            label="OTP Code"
                            label:sr-only
                            class="mx-auto"
                        />
                    </div>

                    <div class="flex items-center space-x-3">
                        <flux:button variant="outline" class="flex-1" wire:click="resetVerification">
                            {{ __('Back') }}
                        </flux:button>

                        <flux:button
                            variant="primary"
                            class="flex-1"
                            wire:click="confirmTwoFactor"
                            x-bind:disabled="$wire.code.length < 6"
                        >
                            {{ __('Confirm') }}
                        </flux:button>
                    </div>
                </div>
            @else
                @error('setupData')
                    <flux:callout variant="danger" icon="x-circle" heading="{{ $message }}" />
                @enderror

                <div class="flex justify-center">
                    <div class="relative aspect-square w-64 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        @empty($qrCodeSvg)
                            <div class="absolute inset-0 flex animate-pulse items-center justify-center bg-white dark:bg-zinc-700">
                                <flux:icon.loading />
                            </div>
                        @else
                            <div x-data class="flex h-full items-center justify-center p-4">
                                <div
                                    class="rounded bg-white p-3"
                                    :style="$flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark)
                                        ? 'filter: invert(1) brightness(1.5)'
                                        : ''"
                                >
                                    {!! $qrCodeSvg !!}
                                </div>
                            </div>
                        @endempty
                    </div>
                </div>

                <div>
                    <flux:button
                        :disabled="$errors->has('setupData')"
                        variant="primary"
                        class="w-full"
                        wire:click="showVerificationIfNecessary"
                    >
                        {{ $this->modalConfig['buttonText'] }}
                    </flux:button>
                </div>

                <div class="space-y-4">
                    <div class="relative flex w-full items-center justify-center">
                        <div class="absolute inset-0 top-1/2 h-px w-full bg-zinc-200 dark:bg-zinc-600"></div>
                        <span class="relative bg-white px-2 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ __('or, enter the code manually') }}
                        </span>
                    </div>

                    <x-copy-field :value="$manualSetupKey" />
                </div>
            @endif
        </div>
    </flux:modal>
</div>
