@props(['attachments'])

@if ($attachments->isNotEmpty())
    @php
        $galleryImages = $attachments->filter->isImage()->values();
        $galleryIndexById = $galleryImages->pluck('id')->flip();
    @endphp

    <div
        wire:key="attachment-gallery-{{ $attachments->pluck('id')->implode('-') }}"
        x-data="{
            open: false,
            index: 0,
            images: @js($galleryImages->map(static fn (\App\Models\Attachment $image): array => [
                'url' => $image->viewUrl(),
                'download' => $image->downloadUrl(),
                'name' => $image->name,
            ])->all()),
            show(i) { this.index = i; this.open = true },
            close() { this.open = false },
            next() { this.index = (this.index + 1) % this.images.length },
            prev() { this.index = (this.index - 1 + this.images.length) % this.images.length },
        }"
        @keydown.window.escape="close()"
        @keydown.window.arrow-right="open && next()"
        @keydown.window.arrow-left="open && prev()"
        {{ $attributes->merge(['class' => 'grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4']) }}
    >
        @foreach ($attachments as $attachment)
            <div wire:key="attachment-{{ $attachment->id }}" class="group relative min-w-0">
                @if ($attachment->isImage())
                    <button
                        type="button"
                        x-on:click="show({{ $galleryIndexById[$attachment->id] }})"
                        data-test="attachment-open-{{ $attachment->id }}"
                        title="{{ $attachment->name }} ({{ \Illuminate\Support\Number::fileSize($attachment->size) }})"
                        aria-label="{{ __('Preview :name', ['name' => $attachment->name]) }}"
                        class="block w-full cursor-pointer overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600"
                    >
                        <div class="flex h-28 items-center justify-center">
                            @if ($attachment->hasThumbnail())
                                <img
                                    src="{{ $attachment->thumbnailUrl() }}"
                                    alt=""
                                    loading="lazy"
                                    class="size-full object-cover object-top"
                                />
                            @else
                                <flux:icon :name="$attachment->iconName()" class="size-10 text-zinc-400" />
                            @endif
                        </div>
                    </button>
                @else
                    <a
                        href="{{ $attachment->viewUrl() }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-test="attachment-link-{{ $attachment->id }}"
                        title="{{ $attachment->name }} ({{ \Illuminate\Support\Number::fileSize($attachment->size) }})"
                        class="block overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600"
                    >
                        <div class="flex h-28 items-center justify-center">
                            <flux:icon :name="$attachment->iconName()" class="size-10 text-zinc-400" />
                        </div>
                    </a>
                @endif

                <div class="mt-1.5 truncate text-xs text-zinc-600 dark:text-zinc-300" title="{{ $attachment->name }}">
                    {{ $attachment->name }}
                </div>

                @can('delete', $attachment)
                    <button
                        type="button"
                        wire:click="deleteAttachment({{ $attachment->id }})"
                        wire:confirm="{{ __('Remove this attachment?') }}"
                        class="absolute top-1.5 right-1.5 rounded-md bg-white/90 p-1 text-zinc-500 opacity-0 shadow-sm transition group-hover:opacity-100 hover:text-red-500 focus:opacity-100 dark:bg-zinc-900/90 dark:text-zinc-400"
                        aria-label="{{ __('Remove :name', ['name' => $attachment->name]) }}"
                    >
                        <flux:icon name="x-mark" variant="micro" />
                    </button>
                @endcan
            </div>
        @endforeach

        @if ($galleryImages->isNotEmpty())
            {{-- Teleported to <body> so Livewire morphs (e.g. the live-updates
                 poll) never touch the overlay — a morph would re-apply the
                 server-rendered display:none and close an open lightbox. --}}
            <template x-teleport="body">
                <div
                    x-show="open"
                    style="display: none"
                    data-test="attachment-lightbox"
                    role="dialog"
                    aria-modal="true"
                    class="fixed inset-0 z-50 flex flex-col bg-black/90"
                    x-on:click.self="close()"
                >
                    <div class="flex items-center justify-between gap-4 p-4 text-sm text-white">
                        <div class="min-w-0">
                            <div data-test="lightbox-name" class="truncate" x-text="images[index]?.name"></div>
                            <div
                                data-test="lightbox-counter"
                                class="text-white/60"
                                x-text="`${index + 1} / ${images.length}`"
                            ></div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <a
                                x-bind:href="images[index]?.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                data-test="lightbox-original"
                                title="{{ __('Open original') }}"
                                aria-label="{{ __('Open original') }}"
                                class="rounded-md p-2 text-white/70 transition hover:bg-white/10 hover:text-white"
                            >
                                <flux:icon name="arrow-top-right-on-square" variant="mini" />
                            </a>
                            <a
                                x-bind:href="images[index]?.download"
                                data-test="lightbox-download"
                                title="{{ __('Download') }}"
                                aria-label="{{ __('Download') }}"
                                class="rounded-md p-2 text-white/70 transition hover:bg-white/10 hover:text-white"
                            >
                                <flux:icon name="arrow-down-tray" variant="mini" />
                            </a>
                            <button
                                type="button"
                                x-on:click="close()"
                                data-test="lightbox-close"
                                aria-label="{{ __('Close') }}"
                                class="rounded-md p-2 text-white/70 transition hover:bg-white/10 hover:text-white"
                            >
                                <flux:icon name="x-mark" variant="mini" />
                            </button>
                        </div>
                    </div>

                    <div
                        class="relative flex min-h-0 flex-1 items-center justify-center p-4 pt-0"
                        x-on:click.self="close()"
                    >
                        <img
                            x-bind:src="images[index]?.url"
                            x-bind:alt="images[index]?.name"
                            class="max-h-full max-w-full rounded-md object-contain"
                        />

                        <template x-if="images.length > 1">
                            <div>
                                <button
                                    type="button"
                                    x-on:click="prev()"
                                    data-test="lightbox-prev"
                                    aria-label="{{ __('Previous image') }}"
                                    class="absolute top-1/2 left-4 -translate-y-1/2 rounded-full bg-white/10 p-2 text-white/80 transition hover:bg-white/20 hover:text-white"
                                >
                                    <flux:icon name="chevron-left" />
                                </button>
                                <button
                                    type="button"
                                    x-on:click="next()"
                                    data-test="lightbox-next"
                                    aria-label="{{ __('Next image') }}"
                                    class="absolute top-1/2 right-4 -translate-y-1/2 rounded-full bg-white/10 p-2 text-white/80 transition hover:bg-white/20 hover:text-white"
                                >
                                    <flux:icon name="chevron-right" />
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        @endif
    </div>
@endif
