@props(['columns', 'blockedIds' => []])

<div class="grid flex-1 grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    @foreach ($columns as $column)
        @php($statusValue = $column['status']->value)
        <div class="flex flex-col rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="flex items-center justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                <flux:badge size="sm" :color="$column['status']->color()" :icon="$column['status']->icon()">
                    {{ $column['status']->label() }}
                </flux:badge>
                <flux:text size="sm" class="text-zinc-400">
                    {{ $column['tasks']->count() }}
                </flux:text>
            </div>

            <div
                x-data="kanbanList"
                data-task-list
                data-status="{{ $statusValue }}"
                class="flex flex-1 flex-col gap-2 overflow-y-auto p-3"
            >
                @forelse ($column['tasks'] as $task)
                    @php($story = $task->story)
                    <div
                        wire:key="task-{{ $task->id }}"
                        data-task-card
                        data-task-id="{{ $task->id }}"
                        @class([
                            'group cursor-grab rounded-lg border border-zinc-200 bg-white p-3 shadow-sm active:cursor-grabbing dark:border-zinc-700 dark:bg-zinc-900',
                            'opacity-60' => $task->isArchived(),
                        ])
                    >
                        <div class="mb-1.5 flex items-start justify-between gap-2">
                            {{-- Story badge — hover (or focus) reveals the full story title --}}
                            <flux:tooltip :content="$story->title">
                                <a
                                    href="{{ route('story.show', ['short_name' => $story->project->short_name, 'story_number' => $story->story_number]) }}"
                                    wire:navigate
                                    class="w-fit"
                                    data-test="story-badge-{{ $task->id }}"
                                >
                                    <flux:badge size="sm" color="zinc" icon="book-open">{{ $story->reference }}</flux:badge>
                                </a>
                            </flux:tooltip>

                            <div class="flex items-center gap-1">
                                <flux:text size="xs" class="font-mono text-zinc-400">{{ $task->reference }}</flux:text>

                                {{-- Accessible, keyboard-operable alternative to dragging --}}
                                <flux:dropdown
                                    position="bottom"
                                    align="end"
                                    data-no-drag
                                    class="opacity-0 transition focus-within:opacity-100 group-hover:opacity-100"
                                >
                                    <flux:button
                                        size="xs"
                                        variant="subtle"
                                        icon="ellipsis-horizontal"
                                        inset
                                        :aria-label="__('Move :task', ['task' => $task->reference])"
                                        :data-test="'move-menu-'.$task->id"
                                    />
                                    <flux:menu>
                                        <flux:menu.group :heading="__('Move to')">
                                            @foreach (\App\Enums\Status::columns() as $status)
                                                @if ($status !== $task->status)
                                                    <flux:menu.item
                                                        :icon="$status->icon()"
                                                        wire:click="moveTask({{ $task->id }}, '{{ $status->value }}')"
                                                        :data-test="'move-'.$task->id.'-'.$status->value"
                                                    >
                                                        {{ $status->label() }}
                                                    </flux:menu.item>
                                                @endif
                                            @endforeach
                                        </flux:menu.group>
                                        <flux:menu.separator />
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
                            </div>
                        </div>

                        <a
                            href="{{ route('task.show', ['short_name' => $story->project->short_name, 'task_number' => $task->task_number]) }}"
                            wire:navigate
                            class="block"
                        >
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $task->title }}</p>
                        </a>

                        <div class="mt-2 flex flex-wrap items-center gap-1">
                            @if ($task->isArchived())
                                <flux:badge size="sm" color="zinc" icon="archive-box" :data-test="'archived-badge-'.$task->id">
                                    {{ __('Archived') }}
                                </flux:badge>
                            @endif
                            @if (in_array($task->id, $blockedIds, true))
                                <flux:tooltip :content="__('Blocked by an unfinished dependency')">
                                    <flux:badge size="sm" color="red" icon="lock-closed" :data-test="'blocked-'.$task->id">
                                        {{ __('Blocked') }}
                                    </flux:badge>
                                </flux:tooltip>
                            @endif
                            <x-priority-badge :priority="$task->priority" />
                            <x-due-date-badge :date="$task->due_date" />
                            <x-tag-badges :tags="$task->tags" />
                        </div>

                        @if ($task->assignees->isNotEmpty())
                            <div class="mt-2 flex items-center">
                                <flux:avatar.group>
                                    @foreach ($task->assignees as $assignee)
                                        <x-user-avatar :user="$assignee" :tooltip="$assignee->name" />
                                    @endforeach
                                </flux:avatar.group>
                            </div>
                        @endif
                    </div>
                @empty
                    <flux:text size="sm" class="flex flex-1 items-center justify-center text-zinc-400">
                        {{ __('No tasks') }}
                    </flux:text>
                @endforelse
            </div>
        </div>
    @endforeach
</div>
