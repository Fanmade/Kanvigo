<flux:card>
    <button
        type="button"
        wire:click="toggleCollapsed"
        class="flex items-center gap-2 text-start"
        aria-expanded="{{ $collapsed ? 'false' : 'true' }}"
        aria-controls="activity-body-{{ $subjectId }}"
    >
        <flux:icon :name="$collapsed ? 'chevron-right' : 'chevron-down'" variant="micro" class="text-zinc-400" />
        <flux:heading size="sm">{{ __('Activity') }}</flux:heading>
        <flux:badge size="sm" color="zinc">{{ $this->activityCount }}</flux:badge>
    </button>

    @unless ($collapsed)
        <ul id="activity-body-{{ $subjectId }}" class="mt-3 flex flex-col gap-3">
            @forelse ($this->activities as $activity)
                @php
                    $old = \App\Enums\Status::tryFrom((string) $activity->old_value)?->label() ?? $activity->old_value;
                    $new = \App\Enums\Status::tryFrom((string) $activity->new_value)?->label() ?? $activity->new_value;
                    $oldPriority = \App\Enums\Priority::tryFrom((int) $activity->old_value)?->label() ?? $activity->old_value;
                    $newPriority = \App\Enums\Priority::tryFrom((int) $activity->new_value)?->label() ?? $activity->new_value;
                    $description = match ($activity->action) {
                        'created' => __('created this'),
                        'status_changed' => __('changed status from :old to :new', ['old' => $old, 'new' => $new]),
                        'priority_changed' => __('changed priority from :old to :new', ['old' => $oldPriority, 'new' => $newPriority]),
                        'assignee_changed' => __('updated the assignees'),
                        'keywords_changed' => __('updated the keywords'),
                        'commented' => __('added a comment'),
                        default => $activity->action,
                    };
                @endphp
                <li class="flex items-start gap-2 text-sm">
                    <flux:avatar size="xs" :name="$activity->user?->name ?? __('System')" />
                    <div class="text-zinc-600 dark:text-zinc-300">
                        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $activity->user?->name ?? __('System') }}</span>
                        {{ $description }}
                        <span class="text-zinc-400">· {{ $activity->created_at?->diffForHumans() }}</span>
                    </div>
                </li>
            @empty
                <li><flux:text size="sm" class="text-zinc-400">{{ __('No activity yet.') }}</flux:text></li>
            @endforelse
        </ul>
    @endunless
</flux:card>
