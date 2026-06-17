<div wire:poll.30s>
    @php($count = $this->unreadCount)

    <flux:dropdown position="bottom" align="end">
        <button type="button" class="cursor-pointer" aria-label="{{ __('Notifications') }}">
            <flux:avatar
                size="sm"
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
                :badge="$count > 0 ? ($count > 9 ? '9+' : (string) $count) : null"
                badge:color="red"
                badge:circle
            />
        </button>

        <flux:menu class="w-80">
            <div class="flex items-center justify-between gap-2 px-2 py-1.5">
                <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>
                @if ($count > 0)
                    <flux:button size="xs" variant="ghost" wire:click="markAllRead">{{ __('Mark all read') }}</flux:button>
                @endif
            </div>

            <flux:menu.separator />

            @forelse ($this->notifications as $notification)
                @php($data = $notification->data)
                @php($label = match ($data['action'] ?? '') {
                    'created' => __('created'),
                    'status_changed' => __('changed the status of'),
                    'priority_changed' => __('changed the priority of'),
                    'assignee_changed' => __('updated the assignees of'),
                    'keywords_changed' => __('updated the keywords of'),
                    'commented' => __('commented on'),
                    default => __('updated'),
                })
                <flux:menu.item wire:click="open('{{ $notification->id }}')" class="!h-auto">
                    <div class="flex items-start gap-2 py-0.5 {{ $notification->read_at ? 'opacity-60' : '' }}">
                        <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-transparent' : 'bg-red-500' }}"></span>
                        <div class="min-w-0">
                            <p class="text-sm whitespace-normal">
                                <span class="font-medium">{{ $data['actor'] ?? __('System') }}</span>
                                {{ $label }}
                                <span class="font-mono text-xs text-zinc-500">{{ $data['reference'] }}</span>
                            </p>
                            <p class="text-xs text-zinc-400">{{ $notification->created_at?->diffForHumans() }}</p>
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

            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('Settings') }}
            </flux:menu.item>

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu>
    </flux:dropdown>
</div>
