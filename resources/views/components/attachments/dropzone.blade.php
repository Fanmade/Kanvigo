@props(['enabled' => false, 'property' => 'newFiles'])

@if ($enabled)
    <div
        x-data="{ depth: 0 }"
        x-on:dragenter.prevent="depth++"
        x-on:dragover.prevent
        x-on:dragleave.prevent="depth = Math.max(0, depth - 1)"
        x-on:drop.prevent="
            depth = 0;
            if ($event.dataTransfer.files.length > 0) {
                $wire.uploadMultiple('{{ $property }}', $event.dataTransfer.files, () => {}, () => {});
            }
        "
        {{ $attributes->merge(['class' => 'relative']) }}
    >
        {{ $slot }}

        <div
            x-show="depth > 0"
            x-cloak
            class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-accent bg-white/85 backdrop-blur-sm dark:bg-zinc-900/85"
        >
            <div class="flex items-center gap-2 text-sm font-medium text-accent">
                <flux:icon name="arrow-up-tray" variant="micro" />
                {{ __('Drop files here to upload') }}
            </div>
        </div>
    </div>
@else
    {{ $slot }}
@endif
