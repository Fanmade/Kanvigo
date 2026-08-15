<div wire:poll.30s>
    @php($count = $this->unreadCount)

    <flux:dropdown position="bottom" align="end">
        {{-- A control of its own, left of the account avatar: it carries the
             unread badge and opens the notifications panel only. --}}
        <button
            type="button"
            class="relative me-3 flex cursor-pointer items-center rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-zinc-100"
            aria-label="{{ __('Notifications') }}"
            data-test="notifications-trigger"
        >
            <flux:icon.bell variant="outline" class="size-5" />

            @if ($this->unreadBadge !== null)
                <span
                    class="absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] leading-4 font-medium text-white"
                    data-test="notifications-badge"
                >{{ $this->unreadBadge }}</span>
            @endif
        </button>

        <flux:menu class="w-80" data-test="notifications-panel">
            <div class="flex items-center justify-between gap-2 px-2 py-1.5">
                <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>
                <div class="flex items-center gap-1">
                    @if ($count > 0)
                        <flux:button
                            size="xs"
                            variant="ghost"
                            wire:click="markAllRead"
                            data-test="mark-all-read"
                        >{{ __('Mark all read') }}</flux:button>
                    @endif
                    @if ($this->notifications->isNotEmpty())
                        <flux:button
                            size="xs"
                            variant="ghost"
                            wire:click="clearAll"
                            data-test="clear-all-notifications"
                        >{{ __('Clear all') }}</flux:button>
                    @endif
                </div>
            </div>

            <flux:menu.separator />

            @forelse ($this->notifications as $notification)
                @php($data = $notification->data)
                @php($label = $this->actionLabel($data['action'] ?? ''))
                {{--
                    A plain row rather than a flux:menu.item: the dismiss button
                    sits inside the row, and nesting it in a menu item would fire
                    the item's own click handler as well.
                --}}
                <div class="group flex items-start gap-1 rounded-md pr-1 hover:bg-zinc-100 dark:hover:bg-zinc-700">
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 cursor-pointer items-start gap-2 px-2 py-1.5 text-left"
                        wire:click="open('{{ $notification->id }}')"
                        data-test="notification-{{ $notification->id }}"
                    >
                        <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-transparent' : 'bg-red-500' }}"></span>
                        <span class="min-w-0 {{ $notification->read_at ? 'opacity-60' : '' }}">
                            <span class="block text-sm whitespace-normal">
                                <span class="font-medium">{{ $data['actor'] ?? __('System') }}</span>
                                {{ $label }}
                                <span class="font-mono text-xs text-zinc-500">{{ $data['reference'] }}</span>
                            </span>
                            <span class="block text-xs text-zinc-400"
                                ><x-relative-time :date="$notification->created_at"
                            /></span>
                        </span>
                    </button>

                    <flux:button
                        size="xs"
                        variant="subtle"
                        icon="x-mark"
                        class="mt-1 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                        :aria-label="__('Dismiss notification')"
                        wire:click="dismiss('{{ $notification->id }}')"
                        data-test="dismiss-notification-{{ $notification->id }}"
                    />
                </div>
            @empty
                <div class="px-3 py-6 text-center">
                    <flux:text size="sm" class="text-zinc-400">{{ __('No notifications.') }}</flux:text>
                </div>
            @endforelse

            <flux:menu.separator />

            <flux:menu.item :href="route('notifications.index')" icon="bell" wire:navigate>
                {{ __('Manage notifications') }}
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
