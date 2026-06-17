<div x-data x-on:modal-show.document="$event.detail?.name === 'command-palette' && ($wire.open = true)">
    {{-- Custom shortcut: Flux's shortcut="cmd.k" maps to the Meta key only (Alpine's
         .cmd alias), so Ctrl+K never fires on Windows/Linux. Handle both modifiers. --}}
    <flux:modal.trigger
        name="command-palette"
        x-on:keydown.window="if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k') { $event.preventDefault(); $dispatch('modal-show', { name: 'command-palette' }) }"
    >
        <flux:input
            as="button"
            icon="magnifying-glass"
            :placeholder="__('Search…')"
            class="w-48 sm:w-64 md:w-80"
            :aria-label="__('Search')"
            data-test="command-palette-trigger"
        >
            {{-- Platform-accurate shortcut hint: ⌘K on Apple, Ctrl K elsewhere. --}}
            <x-slot:kbd>
                <span
                    x-data="{ mac: (navigator.userAgentData?.platform || navigator.platform || '').toLowerCase().includes('mac') }"
                    x-text="mac ? '⌘K' : 'Ctrl K'"
                >⌘K</span>
            </x-slot:kbd>
        </flux:input>
    </flux:modal.trigger>

    <flux:modal name="command-palette" variant="bare" wire:close="close" class="mx-auto w-full max-w-2xl my-[12vh] max-h-screen overflow-y-hidden">
        {{-- filter="manual" disables Flux's client-side text filtering so server-side
             matches (keywords, reference jumps) are never hidden by the typed query. --}}
        <flux:command filter="manual" class="flex w-full flex-col border-none shadow-lg max-h-[76vh]">
            <flux:command.input
                wire:model.live.debounce.200ms="query"
                :placeholder="__('Search projects, stories, tasks…')"
                closable
                autofocus
                data-test="command-palette-input"
            />

            <flux:command.items>
                {{-- Only render items while open: a closed palette keeps no (hidden) item
                     text in the DOM, so it never shadows page elements or selectors. --}}
                @if ($open)
                    @php($items = $this->results->merge($this->actions))

                    @forelse ($items as $item)
                        <flux:command.item wire:click="go('{{ $item->url }}')" :icon="$item->icon" wire:key="{{ $item->type }}-{{ $loop->index }}">
                            <span class="flex w-full items-center gap-2">
                                <span class="flex-1 truncate">{{ $item->title }}</span>
                                @if ($item->reference)
                                    <flux:badge size="sm" color="{{ $item->pinned ? 'blue' : 'zinc' }}">{{ $item->reference }}</flux:badge>
                                @endif
                            </span>
                        </flux:command.item>
                    @empty
                        <flux:text class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No results') }}</flux:text>
                    @endforelse
                @endif
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
