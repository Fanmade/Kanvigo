<div wire:poll.30s>
    @php($count = $this->unreadCount)

    <flux:dropdown position="bottom" align="end">
        <button type="button" class="flex cursor-pointer items-center" aria-label="{{ __('Notifications') }}" data-test="notifications-trigger">
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
                @if ($count > 0)
                    <flux:button size="xs" variant="ghost" wire:click="markAllRead" data-test="mark-all-read">{{ __('Mark all read') }}</flux:button>
                @endif
            </div>

            <flux:menu.separator />

            @forelse ($this->notifications as $notification)
                @php($data = $notification->data)
                @php($label = $this->actionLabel($data['action'] ?? ''))
                <flux:menu.item wire:click="open('{{ $notification->id }}')" class="!h-auto" data-test="notification-{{ $notification->id }}">
                    <div class="flex items-start gap-2 py-0.5 {{ $notification->read_at ? 'opacity-60' : '' }}">
                        <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-transparent' : 'bg-red-500' }}"></span>
                        <div class="min-w-0">
                            <p class="text-sm whitespace-normal">
                                <span class="font-medium">{{ $data['actor'] ?? __('System') }}</span>
                                {{ $label }}
                                <span class="font-mono text-xs text-zinc-500">{{ $data['reference'] }}</span>
                            </p>
                            <p class="text-xs text-zinc-400"><x-relative-time :date="$notification->created_at" /></p>
                        </div>
                    </div>
                </flux:menu.item>
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
