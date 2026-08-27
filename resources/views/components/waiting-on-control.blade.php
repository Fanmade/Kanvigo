@props([
    'task',
    'members',
    'canEdit' => false,
])

{{--
    Rail control for the task's single "waiting on" member: the badge when the
    task is waiting, and — for anyone who may edit the task — a searchable member
    list that sets it in one click, plus a "not waiting" entry that clears it.
--}}
<div class="flex min-w-0 items-center gap-1">
    @if ($task->waitingOn)
        <x-waiting-on-badge :task="$task" />
    @else
        <flux:text size="sm" class="text-zinc-400">{{ __('Nobody') }}</flux:text>
    @endif

    @if ($canEdit)
        <flux:dropdown align="end" data-test="waiting-on-control">
            <flux:button size="xs" variant="subtle" icon="plus" :aria-label="__('Edit who this task waits on')" />

            <flux:popover x-data="{ q: '' }" class="flex max-h-72 w-64 flex-col gap-2 overflow-hidden">
                <flux:input size="sm" x-model="q" icon="magnifying-glass" :placeholder="__('Search members…')" />

                <div class="flex flex-col gap-0.5 overflow-y-auto">
                    @if ($task->waitingOn)
                        <button
                            type="button"
                            wire:click="clearWaitingOn"
                            class="flex items-center gap-2 rounded-md px-2 py-1 text-start text-sm text-zinc-800 hover:bg-zinc-100 dark:text-white dark:hover:bg-zinc-700"
                            data-test="clear-waiting-on"
                        >
                            <flux:icon.x-mark variant="micro" class="text-zinc-400" />
                            {{ __('Not waiting') }}
                        </button>
                    @endif

                    @forelse ($members as $member)
                        <button
                            type="button"
                            wire:key="waiting-on-option-{{ $member->id }}"
                            wire:click="setWaitingOn({{ $member->id }})"
                            x-show="q.trim() === '' || @js(\Illuminate\Support\Str::lower($member->name)).includes(q.trim().toLowerCase())"
                            class="flex items-center gap-2 rounded-md px-2 py-1 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700"
                            data-test="waiting-on-option-{{ $member->id }}"
                        >
                            <x-user-avatar :user="$member" circle size="xs" />
                            <span class="truncate text-sm text-zinc-800 dark:text-white">{{ $member->name }}</span>
                        </button>
                    @empty
                        <flux:text size="sm" class="px-2 text-zinc-400">{{ __('No members') }}</flux:text>
                    @endforelse
                </div>
            </flux:popover>
        </flux:dropdown>
    @endif
</div>
