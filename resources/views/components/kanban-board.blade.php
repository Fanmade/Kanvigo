@props(['columns'])

<div class="grid flex-1 grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    @foreach ($columns as $column)
        @php($statusValue = $column['status']->value)
        <div
            x-data="{ over: false }"
            x-bind:class="over && 'ring-2 ring-indigo-400'"
            @dragover.prevent="over = true"
            @dragleave="over = false"
            @drop.prevent="over = false; $wire.moveTask(parseInt($event.dataTransfer.getData('taskId')), '{{ $statusValue }}')"
            class="flex flex-col rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50"
        >
            <div class="flex items-center justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                <flux:badge size="sm" :color="$column['status']->color()" :icon="$column['status']->icon()">
                    {{ $column['status']->label() }}
                </flux:badge>
                <flux:text size="sm" class="text-zinc-400">
                    {{ collect($column['groups'])->sum(fn ($g) => $g['tasks']->count()) }}
                </flux:text>
            </div>

            <div class="flex flex-1 flex-col gap-3 overflow-y-auto p-3">
                @forelse ($column['groups'] as $group)
                    @php($story = $group['story'])
                    <div class="flex flex-col gap-2 rounded-lg border border-dashed border-zinc-300 p-2 dark:border-zinc-600">
                        <a
                            href="{{ route('story.show', ['short_name' => $story->project->short_name, 'story_number' => $story->story_number]) }}"
                            wire:navigate
                            class="flex items-center gap-1.5 text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
                        >
                            <flux:icon.book-open variant="micro" />
                            {{ $story->reference }} · {{ $story->title }}
                        </a>

                        @foreach ($group['tasks'] as $task)
                            <div
                                wire:key="task-{{ $task->id }}"
                                draggable="true"
                                @dragstart="$event.dataTransfer.setData('taskId', '{{ $task->id }}')"
                                class="group cursor-grab rounded-lg border border-zinc-200 bg-white p-3 shadow-sm active:cursor-grabbing dark:border-zinc-700 dark:bg-zinc-900"
                            >
                                <a
                                    href="{{ route('task.show', ['short_name' => $story->project->short_name, 'story_number' => $story->story_number, 'task_number' => $task->task_number]) }}"
                                    wire:navigate
                                    class="block"
                                >
                                    <flux:text size="xs" class="font-mono text-zinc-400">{{ $task->reference }}</flux:text>
                                    <p class="mt-0.5 text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $task->title }}</p>
                                </a>

                                <div class="mt-2 flex flex-wrap items-center gap-1">
                                    <x-priority-badge :priority="$task->priority" />
                                    <x-due-date-badge :date="$task->due_date" />
                                    <x-tag-badges :tags="$task->tags" />
                                </div>

                                @if ($task->assignees->isNotEmpty())
                                    <div class="mt-2 flex items-center">
                                        <flux:avatar.group>
                                            @foreach ($task->assignees as $assignee)
                                                <flux:avatar size="xs" :name="$assignee->name" />
                                            @endforeach
                                        </flux:avatar.group>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @empty
                    <flux:text size="sm" class="px-1 py-2 text-zinc-400">{{ __('No tasks') }}</flux:text>
                @endforelse
            </div>
        </div>
    @endforeach
</div>
