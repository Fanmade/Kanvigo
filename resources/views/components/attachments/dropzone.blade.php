@props(['enabled' => false, 'property' => 'newFiles', 'maxSize' => null])

@php
    $maxSizeKb = (int) ($maxSize ?? config('attachments.max_size'));

    $maxSizeLabel = $maxSizeKb >= 1024
        ? rtrim(rtrim(number_format($maxSizeKb / 1024, 1, '.', ''), '0'), '.').' MB'
        : $maxSizeKb.' KB';
@endphp

@if ($enabled)
    <div
        x-data="{
            depth: 0,
            maxBytes: {{ $maxSizeKb * 1024 }},
            // Greedily pack files into batches whose combined size stays within
            // the per-file limit: uploadMultiple() sends a whole batch as one
            // POST, so an unchunked multi-file drop can exceed the web server's
            // request body limit even when every file is individually fine.
            // Capping each request at the single-file limit guarantees that any
            // batch fits wherever a single maximum-size file already does.
            batches(files) {
                const result = [];
                let batch = [];
                let bytes = 0;

                files.forEach(file => {
                    if (batch.length > 0 && bytes + file.size > this.maxBytes) {
                        result.push(batch);
                        batch = [];
                        bytes = 0;
                    }

                    batch.push(file);
                    bytes += file.size;
                });

                if (batch.length > 0) {
                    result.push(batch);
                }

                return result;
            },
            async handleDrop(files) {
                this.depth = 0;

                const dropped = Array.from(files || []);

                if (dropped.length === 0) {
                    return;
                }

                // Reject oversized files up front with a clear message instead of
                // letting the upload silently fail against the server-side limit.
                const tooLarge = dropped.filter(file => file.size > this.maxBytes);
                const accepted = dropped.filter(file => file.size <= this.maxBytes);

                tooLarge.forEach(file => this.$flux.toast({
                    text: @js(__(':name is too large. The maximum file size is :size.', ['size' => $maxSizeLabel])).replace(':name', () => file.name),
                    variant: 'danger',
                }));

                for (const batch of this.batches(accepted)) {
                    const uploaded = await new Promise(resolve => this.$wire.uploadMultiple(
                        '{{ $property }}',
                        batch,
                        () => resolve(true),
                        () => resolve(false),
                    ));

                    // One toast and stop — the remaining batches would most
                    // likely fail the same way.
                    if (!uploaded) {
                        this.$flux.toast({
                            text: @js(__('Upload failed. Please try again.')),
                            variant: 'danger',
                        });

                        return;
                    }
                }
            },
        }"
        x-on:dragenter.prevent="depth++"
        x-on:dragover.prevent
        x-on:dragleave.prevent="depth = Math.max(0, depth - 1)"
        x-on:drop.prevent="handleDrop($event.dataTransfer.files)"
        data-test="description-dropzone"
        {{ $attributes->merge(['class' => 'relative']) }}
    >
        {{ $slot }}

        <div
            x-show="depth > 0"
            x-cloak
            class="border-accent pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-xl border-2 border-dashed bg-white/85 backdrop-blur-sm dark:bg-zinc-900/85"
        >
            <div class="text-accent flex items-center gap-2 text-sm font-medium">
                <flux:icon name="arrow-up-tray" variant="micro" />
                {{ __('Drop files here to upload') }}
            </div>
        </div>
    </div>
@else
    {{ $slot }}
@endif
