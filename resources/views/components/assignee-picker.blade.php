@props([
    'members',
    'selected',
    'model',
    'canEdit' => true,
])

{{--
    Shared one-click assignee picker. Shows the current assignees as an avatar
    group (or "Unassigned") and, when editable, a "+" that opens a searchable
    member list in a single click. Bound to the host component's array property
    named by `model` (e.g. "assigneeIds") via checkboxes.
--}}
<div class="flex min-w-0 items-center gap-1">
    @if ($selected->isNotEmpty())
        <flux:avatar.group>
            @foreach ($selected as $assignee)
                <x-user-avatar :user="$assignee" circle :tooltip="$assignee->name" />
            @endforeach
        </flux:avatar.group>
    @else
        <flux:text size="sm" class="text-zinc-400">{{ __('Unassigned') }}</flux:text>
    @endif

    @if ($canEdit)
        <flux:dropdown align="end" data-test="assignees-control">
            <flux:button size="xs" variant="subtle" icon="plus" :aria-label="__('Edit assignees')" />

            <flux:popover x-data="{ q: '' }" class="flex max-h-72 w-64 flex-col gap-2 overflow-hidden">
                <flux:input size="sm" x-model="q" icon="magnifying-glass" :placeholder="__('Search members…')" />

                <div class="flex flex-col gap-0.5 overflow-y-auto">
                    @forelse ($members as $member)
                        <label
                            wire:key="assignee-option-{{ $member->id }}"
                            x-show="q.trim() === '' || @js(\Illuminate\Support\Str::lower($member->name)).includes(q.trim().toLowerCase())"
                            class="flex items-center gap-2 rounded-md px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-700"
                        >
                            <flux:checkbox wire:model.live="{{ $model }}" :value="$member->id" />
                            <x-user-avatar :user="$member" circle size="xs" />
                            <span class="truncate text-sm text-zinc-800 dark:text-white">{{ $member->name }}</span>
                        </label>
                    @empty
                        <flux:text size="sm" class="px-2 text-zinc-400">{{ __('No members') }}</flux:text>
                    @endforelse
                </div>
            </flux:popover>
        </flux:dropdown>
    @endif
</div>
