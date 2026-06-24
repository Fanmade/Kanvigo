<div class="app-content mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Notifications') }}</flux:heading>
        <flux:subheading>{{ __('Everything you are subscribed to. Disable any to stop receiving updates.') }}</flux:subheading>
    </div>

    @php($groups = collect($this->rows)->groupBy('group'))

    @forelse ($groups as $group => $items)
        <div class="flex flex-col gap-2">
            <flux:heading size="lg">{{ $group }}</flux:heading>

            @foreach ($items as $row)
                <flux:card class="flex items-center justify-between gap-3" wire:key="sub-{{ $row['type'] }}-{{ $row['id'] }}">
                    <a href="{{ $row['url'] }}" wire:navigate class="min-w-0 truncate text-sm font-medium hover:underline">
                        {{ $row['label'] }}
                    </a>

                    <div class="flex shrink-0 items-center gap-2">
                        <flux:tooltip :content="__('Notifications for this item')">
                            <flux:badge size="sm" color="zinc" icon="bell">{{ $row['total'] }}</flux:badge>
                        </flux:tooltip>

                        @if ($row['unread'] > 0)
                            <flux:badge size="sm" color="red">{{ $row['unread'] }} {{ __('unread') }}</flux:badge>
                        @endif

                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="bell-slash"
                            wire:click="unsubscribe('{{ $row['type'] }}', {{ $row['id'] }})"
                        >
                            {{ __('Disable') }}
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @empty
        <flux:card>
            <flux:text class="text-zinc-400">{{ __('You are not subscribed to anything yet.') }}</flux:text>
        </flux:card>
    @endforelse
</div>
