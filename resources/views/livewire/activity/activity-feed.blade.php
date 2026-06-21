<flux:card>
    <button
        type="button"
        wire:click="toggleCollapsed"
        class="flex items-center gap-2 text-start"
        aria-expanded="{{ $collapsed ? 'false' : 'true' }}"
        aria-controls="activity-body-{{ $morphSubjectId }}"
    >
        <flux:icon :name="$collapsed ? 'chevron-right' : 'chevron-down'" variant="micro" class="text-zinc-400" />
        <flux:heading size="sm">{{ __('Activity') }}</flux:heading>
        <flux:badge size="sm" color="zinc">{{ $this->activityCount }}</flux:badge>
    </button>

    @unless ($collapsed)
        <ul id="activity-body-{{ $morphSubjectId }}" class="mt-3 flex flex-col gap-3">
            @forelse ($this->activities as $activity)
                <li class="flex items-start gap-2 text-sm">
                    <x-user-avatar :user="$activity->user" :name="$activity->user?->name ?? __('System')" />
                    <div class="text-zinc-600 dark:text-zinc-300">
                        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $activity->user?->name ?? __('System') }}</span>
                        {{ $this->descriptions[$activity->id] }}
                        <span class="text-zinc-400">· {{ $activity->created_at?->diffForHumans() }}</span>
                        @if ($activity->token_name)
                            <span class="text-zinc-400" data-test="activity-source">· {{ __('via token “:name”', ['name' => $activity->token_name]) }}</span>
                        @endif
                    </div>
                </li>
            @empty
                <li><flux:text size="sm" class="text-zinc-400">{{ __('No activity yet.') }}</flux:text></li>
            @endforelse
        </ul>
    @endunless
</flux:card>
