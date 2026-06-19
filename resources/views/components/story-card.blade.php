@props(['story', 'shortName', 'hideFinishedTasks' => false])

@php
    $visibleTasks = $hideFinishedTasks
        ? $story->tasks->reject(fn ($task) => $task->status === \App\Enums\Status::Done)
        : $story->tasks;
@endphp

<flux:card class="p-0">
    <a
        href="{{ route('story.show', ['short_name' => $shortName, 'story_number' => $story->story_number]) }}"
        wire:navigate
        class="flex min-w-0 items-center gap-2 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800"
    >
        <flux:icon.book-open variant="micro" class="text-zinc-400" />
        <span class="font-mono text-xs text-zinc-400">{{ $shortName }}{{ $story->story_number }}</span>
        <span class="truncate text-sm font-medium">{{ $story->title }}</span>
    </a>

    <x-story-progress :progress="$story->progress()" bar-class="flex-1" class="px-4 pb-3" />

    @if ($story->tags->isNotEmpty())
        <div class="px-4 pb-3">
            <x-tag-badges :tags="$story->tags" />
        </div>
    @endif

    @if ($visibleTasks->isNotEmpty())
        <div class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($visibleTasks as $task)
                <a
                    href="{{ route('task.show', ['short_name' => $shortName, 'story_number' => $story->story_number, 'task_number' => $task->task_number]) }}"
                    wire:navigate
                    class="flex items-center justify-between gap-2 px-4 py-2 ps-9 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="font-mono text-xs text-zinc-400">-{{ $task->task_number }}</span>
                        <span class="truncate text-sm">{{ $task->title }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if ($task->assignees->isNotEmpty())
                            <flux:avatar.group>
                                @foreach ($task->assignees as $assignee)
                                    <x-user-avatar :user="$assignee" :tooltip="$assignee->name" />
                                @endforeach
                            </flux:avatar.group>
                        @endif
                        <flux:badge size="sm" :color="$task->status->color()" :icon="$task->status->icon()">
                            {{ $task->status->label() }}
                        </flux:badge>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</flux:card>
