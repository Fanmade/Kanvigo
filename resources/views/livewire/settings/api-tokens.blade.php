<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('API tokens') }}</flux:heading>

    <x-settings.layout :heading="__('API tokens')" :subheading="__('Manage your personal API tokens used for MCP and API access')">
        <form method="POST" wire:submit="createToken" class="mt-6 space-y-6">
            <flux:input
                wire:model="name"
                :label="__('Token name')"
                type="text"
                required
                autocomplete="off"
                :placeholder="__('e.g. My laptop')"
            />

            <flux:radio.group wire:model="accessLevel" :label="__('Access level')" variant="cards" class="max-sm:flex-col">
                <flux:radio value="read" :label="__('Read-only')" :description="__('Can read data')" />
                <flux:radio value="write" :label="__('Read and write')" :description="__('Can read and modify data')" />
            </flux:radio.group>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="create-token-button">{{ __('Create token') }}</flux:button>
            </div>
        </form>

        @if ($plainTextToken)
            <div class="mt-6 space-y-3" wire:cloak>
                <flux:callout variant="success" icon="check-circle" :heading="__('Token created')">
                    <flux:callout.text>
                        {{ __('Copy your new API token now. For your security, it will not be shown again.') }}
                    </flux:callout.text>
                </flux:callout>

                <div
                    class="flex items-center space-x-2"
                    x-data="{
                        copied: false,
                        async copy() {
                            try {
                                await navigator.clipboard.writeText('{{ $plainTextToken }}');
                                this.copied = true;
                                setTimeout(() => this.copied = false, 1500);
                            } catch (e) {
                                console.warn('Could not copy to clipboard');
                            }
                        }
                    }"
                >
                    <div class="flex items-stretch w-full border rounded-xl dark:border-stone-700">
                        <input
                            type="text"
                            readonly
                            value="{{ $plainTextToken }}"
                            class="w-full p-3 bg-transparent outline-none text-stone-900 dark:text-stone-100 font-mono text-sm"
                        />

                        <button
                            type="button"
                            @click="copy()"
                            class="px-3 transition-colors border-l cursor-pointer border-stone-200 dark:border-stone-600"
                        >
                            <flux:icon.document-duplicate x-show="!copied" variant="outline"></flux:icon>
                            <flux:icon.check
                                x-show="copied"
                                variant="solid"
                                class="text-green-500"
                            ></flux:icon>
                        </button>
                    </div>

                    <flux:button variant="ghost" size="sm" wire:click="dismissToken">{{ __('Done') }}</flux:button>
                </div>
            </div>
        @endif

        <section class="mt-12">
            <flux:heading>{{ __('Active tokens') }}</flux:heading>
            <flux:subheading>{{ __('Tokens that can be used to access the API on your behalf') }}</flux:subheading>

            <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                <div class="border rounded-lg border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    @forelse ($this->tokens as $token)
                        <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                            <div class="flex items-center gap-4">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon.key class="size-5 text-zinc-500 dark:text-zinc-400" />
                                </div>
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2.5">
                                        <p class="font-medium tracking-tight">{{ $token['name'] }}</p>
                                        <flux:badge size="sm">{{ $token['abilities_label'] }}</flux:badge>
                                    </div>
                                    <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                        {{ __('Created :time', ['time' => $token['created_at_diff']]) }}
                                        @if ($token['last_used_at_diff'])
                                            <span class="opacity-50 mx-1">/</span>
                                            {{ __('Last used :time', ['time' => $token['last_used_at_diff']]) }}
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                icon:variant="outline"
                                wire:click="revoke({{ $token['id'] }})"
                                class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                            />
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.key class="size-7 text-zinc-400 dark:text-zinc-500" />
                            </div>
                            <p class="font-medium">{{ __('No API tokens yet') }}</p>
                            <flux:text class="mt-1">{{ __('Create a token to access the API') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>
        </section>
    </x-settings.layout>
</section>
