<div>
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
                {{-- Inline the platform check in x-text so there is no x-data state to
                     race against: x-text is always self-contained, never referencing an
                     uninitialized scope (avoids the flaky "mac is not defined"). --}}
                {{-- Hidden below the `sm` breakpoint: the hint doesn't fit the
                     narrow mobile search bar and isn't useful without a keyboard. --}}
                <span
                    data-test="command-palette-shortcut"
                    class="hidden sm:inline"
                    x-text="(navigator.userAgentData?.platform || navigator.platform || '').toLowerCase().includes('mac') ? '⌘K' : 'Ctrl K'"
                >⌘K</span>
            </x-slot:kbd>
        </flux:input>
    </flux:modal.trigger>

    <flux:modal name="command-palette" variant="bare" wire:close="close" class="mx-auto w-full max-w-2xl my-[12vh] max-h-screen overflow-y-hidden">
        {{-- filter="manual" disables Flux's client-side text filtering so server-side
             matches (tags, reference jumps) are never hidden by the typed query. --}}
        <flux:command filter="manual" class="flex w-full flex-col border-none shadow-lg max-h-[76vh]">
            <flux:command.input
                wire:model.live.debounce.200ms="query"
                :placeholder="__('Search projects and tasks…')"
                closable
                autofocus
                data-test="command-palette-input"
            />

            <flux:command.items>
                @php($items = $this->results->merge($this->actions))

                {{-- The empty state ("No results found") is rendered by Flux's
                     <flux:command.items>; we override that view (resources/views/
                     flux/command/items.blade.php) so the string is translatable. --}}
                @foreach ($items as $item)
                    <flux:command.item wire:click="{{ $item->event ? 'runAction' : 'go' }}('{{ $item->event ?? $item->url }}')" :icon="$item->icon" wire:key="{{ $item->type }}-{{ $loop->index }}" data-test="palette-item-{{ Str::slug($item->title) }}">
                        <span class="flex w-full items-center gap-2">
                            <span class="flex-1 truncate">{{ $item->title }}</span>
                            @if ($item->progress)
                                <x-task-progress :progress="$item->progress" bar-class="w-10" class="shrink-0" />
                            @endif
                            @if ($item->reference)
                                <flux:badge size="sm" color="{{ $item->pinned ? 'blue' : 'zinc' }}">{{ $item->reference }}</flux:badge>
                            @endif
                        </span>
                    </flux:command.item>
                @endforeach
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
