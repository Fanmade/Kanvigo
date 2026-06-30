@props(['value', 'mono' => false])

{{--
    A read-only field with a one-click "copy to clipboard" button (Alpine), showing
    a check for ~1.5s after copying. While $value is empty a loading spinner stands
    in. Anything in the default slot is placed beside the field (e.g. a "Done"
    button). Used for the 2FA manual-setup key and a freshly minted API token.
--}}
<div
    class="flex items-center space-x-2"
    x-data="{
        copied: false,
        async copy() {
            try {
                await navigator.clipboard.writeText(@js($value));
                this.copied = true;
                setTimeout(() => this.copied = false, 1500);
            } catch (e) {
                console.warn('Could not copy to clipboard');
            }
        }
    }"
>
    <div class="flex items-stretch w-full border rounded-xl dark:border-zinc-700">
        @empty($value)
            <div class="flex items-center justify-center w-full p-3 bg-zinc-100 dark:bg-zinc-700">
                <flux:icon.loading variant="mini" />
            </div>
        @else
            <input
                type="text"
                readonly
                value="{{ $value }}"
                @class([
                    'w-full p-3 bg-transparent outline-none text-zinc-900 dark:text-zinc-100',
                    'font-mono text-sm' => $mono,
                ])
            />

            <button
                type="button"
                @click="copy()"
                aria-label="{{ __('Copy to clipboard') }}"
                class="px-3 transition-colors border-l cursor-pointer border-zinc-200 dark:border-zinc-600"
            >
                <flux:icon.document-duplicate x-show="!copied" variant="outline" />
                <flux:icon.check x-show="copied" variant="solid" class="text-green-500" />
            </button>
        @endempty
    </div>

    {{ $slot }}
</div>
