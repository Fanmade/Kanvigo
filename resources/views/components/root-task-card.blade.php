@props([
    'task',
    'shortName',
    'canArchive' => false,
    'showArchived' => false,
])

{{-- Direct children (from the eager-loaded descendants, so no per-card query),
     honouring the overview's "Show archived" toggle. --}}
@php($subtasks = $task->loadedChildren(includeArchived: $showArchived))

<flux:card
    class="flex flex-col gap-3"
    wire:key="root-task-{{ $task->id }}"
    @class(['opacity-60' => $task->isArchived()])
    data-test="root-task-{{ $task->id }}"
>
    <div class="flex items-center justify-between gap-3">
        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
            <div class="flex min-w-0 items-center gap-2">
                <flux:text size="xs" class="font-mono text-zinc-400"
                    >{{ $shortName }}-{{ $task->task_number }}</flux:text>
                <a
                    href="{{ route('task.show', ['short_name' => $shortName, 'task_number' => $task->task_number]) }}"
                    wire:navigate
                    class="truncate text-sm font-medium hover:underline"
                >
                    {{ $task->title }}
                </a>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-tag-badges :tags="$task->tags" />
                <x-task-progress :progress="$task->progress()" bar-class="w-32" />
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($task->assignees->isNotEmpty())
                <flux:avatar.group>
                    @foreach ($task->assignees as $assignee)
                        <x-user-avatar :user="$assignee" circle :tooltip="$assignee->name" />
                    @endforeach
                </flux:avatar.group>
            @endif

            <flux:badge
                size="sm"
                :color="$task->status->color()"
                :icon="$task->status->icon()"
            >{{ $task->status->label() }}</flux:badge>

            @if ($canArchive)
                <flux:dropdown align="end">
                    <flux:button
                        size="xs"
                        variant="subtle"
                        icon="ellipsis-horizontal"
                        :aria-label="__('Actions')"
                        :data-test="'root-task-actions-'.$task->id"
                    />
                    <flux:menu>
                        @if ($task->isArchived())
                            <flux:menu.item
                                icon="arrow-up-tray"
                                wire:click="unarchiveTask({{ $task->id }})"
                                :data-test="'unarchive-'.$task->id"
                            >
                                {{ __('Unarchive') }}
                            </flux:menu.item>
                        @else
                            <flux:menu.item
                                icon="archive-box"
                                wire:click="archiveTask({{ $task->id }})"
                                :data-test="'archive-'.$task->id"
                            >
                                {{ __('Archive') }}
                            </flux:menu.item>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            @endif
        </div>
    </div>

    @if ($subtasks->isNotEmpty())
        <div
            class="flex flex-col divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-700 dark:border-zinc-700"
            data-test="root-task-subtasks-{{ $task->id }}"
        >
            @foreach ($subtasks as $subtask)
                <x-subtask-row :task="$subtask" :short-name="$shortName" test="root-task-subtask" padding="py-2" />
            @endforeach
        </div>
    @endif
</flux:card>
