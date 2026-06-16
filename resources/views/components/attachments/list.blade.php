@props(['attachments'])

@if ($attachments->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4']) }}>
        @foreach ($attachments as $attachment)
            <div wire:key="attachment-{{ $attachment->id }}" class="group relative min-w-0">
                <a
                    href="{{ $attachment->viewUrl() }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="{{ $attachment->name }} ({{ \Illuminate\Support\Number::fileSize($attachment->size) }})"
                    class="block overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600"
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
                </a>

                <div
                    class="mt-1.5 truncate text-xs text-zinc-600 dark:text-zinc-300"
                    title="{{ $attachment->name }}"
                >
                    {{ $attachment->name }}
                </div>

                @can('delete', $attachment)
                    <button
                        type="button"
                        wire:click="deleteAttachment({{ $attachment->id }})"
                        wire:confirm="{{ __('Remove this attachment?') }}"
                        class="absolute right-1.5 top-1.5 rounded-md bg-white/90 p-1 text-zinc-500 opacity-0 shadow-sm transition hover:text-red-500 focus:opacity-100 group-hover:opacity-100 dark:bg-zinc-900/90 dark:text-zinc-400"
                        aria-label="{{ __('Remove :name', ['name' => $attachment->name]) }}"
                    >
                        <flux:icon name="x-mark" variant="micro" />
                    </button>
                @endcan
            </div>
        @endforeach
    </div>
@endif
