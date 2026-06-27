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
        @if ($focusSequence)
            <div
                wire:key="focus-{{ $focusSequence }}"
                x-data
                x-init="$nextTick(() => document.getElementById('log-{{ $focusSequence }}')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
            ></div>
        @endif

        <ul id="activity-body-{{ $morphSubjectId }}" class="mt-3 flex flex-col gap-3">
            @forelse ($this->activities as $activity)
                <li
                    id="log-{{ $activity->sequence }}"
                    @class([
                        'flex items-start gap-2 scroll-mt-24 rounded-lg text-sm transition-colors',
                        '-mx-2 bg-amber-50 px-2 py-1 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:ring-amber-400/20' => $activity->sequence === $focusSequence,
                    ])
                    @if ($activity->sequence === $focusSequence) data-test="focused-activity" @endif
                >
                    <x-user-avatar :user="$activity->user" :name="$activity->user?->name ?? __('System')" />
                    <div class="group/entry flex-1 text-zinc-600 dark:text-zinc-300">
                        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $activity->user?->name ?? __('System') }}</span>
                        {{ $this->descriptions[$activity->id] }}
                        <span class="text-zinc-400">· <x-relative-time :date="$activity->created_at" /></span>
                        {{-- A token-driven action is flagged generically: the token's name is
                             private to its owner, so it is never surfaced to other members. --}}
                        @if ($activity->token_name)
                            <span class="text-zinc-400" data-test="activity-source">· {{ __('via API token') }}</span>
                        @endif
                        @if ($this->subjectReference)
                            <button
                                type="button"
                                wire:click="$dispatch('discuss-activity', { reference: '{{ $this->subjectReference }}-log-{{ $activity->sequence }}' })"
                                class="ms-1 align-baseline text-xs font-medium text-blue-600 underline-offset-2 hover:underline focus:underline focus:outline-none sm:opacity-0 sm:group-hover/entry:opacity-100 sm:focus:opacity-100 dark:text-blue-400"
                                data-test="discuss-activity"
                            >
                                {{ __('Discuss') }}
                            </button>
                        @endif
                    </div>
                </li>
            @empty
                <li><flux:text size="sm" class="text-zinc-400">{{ __('No activity yet.') }}</flux:text></li>
            @endforelse
        </ul>

        @if ($this->hasMoreActivities)
            <flux:button
                type="button"
                size="sm"
                variant="ghost"
                wire:click="showMore"
                class="mt-3 self-center"
                data-test="show-more-activity"
            >
                {{ __('Show older activity') }}
            </flux:button>
        @endif
    @endunless
</flux:card>
