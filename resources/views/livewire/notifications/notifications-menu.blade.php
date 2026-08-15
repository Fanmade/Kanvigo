<div wire:poll.30s>
    @php($count = $this->unreadCount)

    <flux:dropdown position="bottom" align="end">
        <button
            type="button"
            class="flex cursor-pointer items-center"
            aria-label="{{ __('Notifications') }}"
            data-test="notifications-trigger"
        >
            <flux:avatar
                size="sm"
                :name="auth()->user()->name"
                :src="auth()->user()->avatarUrl()"
                :initials="auth()->user()->initials()"
                :badge="$this->unreadBadge"
                badge:color="red"
                badge:circle
            />
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

            <x-account-menu-items />
        </flux:menu>
    </flux:dropdown>
</div>
