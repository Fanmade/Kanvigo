@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => __('Sign in with a passkey'),
    'loadingLabel' => __('Authenticating...'),
    'separator' => __('Or continue with email'),
])

@assets
    @vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        loading: false,
        error: null,
        htmlError: @js(__('Passkey sign-in failed. Please reload the page and try again, or continue with your email and password.')),
        genericError: @js(__('Passkey sign-in failed. Please try again.')),
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async verify() {
            this.loading = true;
            this.error = null;
            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route($optionsRoute) }}',
                        submit: '{{ route($submitRoute) }}',
                    },
                });
                Livewire.navigate(response.redirect || '/dashboard');
            } catch (e) {
                const name = e?.constructor?.name;

                if (name === 'UserCancelledError') {
                    // The user dismissed the passkey prompt — nothing to report.
                } else if (e instanceof SyntaxError || /is not valid JSON|DOCTYPE|Unexpected token/i.test(e?.message ?? '')) {
                    // A passkey endpoint returned HTML (an expired session / 419 page
                    // or a server error) instead of JSON, so the client could not
                    // parse it. Show a friendly message rather than leaking the raw
                    // JSON-parser error to the user.
                    this.error = this.htmlError;
                } else {
                    this.error = e?.message || this.genericError;
                }
            } finally {
                this.loading = false;
            }
        },
    }"
    data-test="passkey-verify-root"
>
    <template x-if="supported">
        <div>
            <div class="grid gap-2">
                <flux:button
                    variant="outline"
                    icon="finger-print"
                    class="w-full"
                    x-on:click="verify()"
                    x-bind:disabled="loading"
                    data-test="passkey-verify"
                >
                    <span x-show="! loading">{{ $label }}</span>
                    <span x-show="loading" x-cloak>{{ $loadingLabel }}</span>
                </flux:button>
                <p x-show="error" x-text="error" x-cloak class="text-center text-sm text-red-600 dark:text-red-400"></p>
            </div>

            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
                </div>
                <div class="relative flex justify-center text-xs uppercase">
                    <span class="bg-white px-2 text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                        {{ $separator }}
                    </span>
                </div>
            </div>
        </div>
    </template>
</div>
