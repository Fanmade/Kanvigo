<div class="flex flex-col gap-6">
    <flux:heading size="xl" data-test="dashboard-heading">{{ __('Dashboard') }}</flux:heading>

    {{-- Statistics --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <flux:card class="flex flex-col gap-1">
            <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                <flux:icon.rectangle-stack variant="micro" />
                <flux:text size="sm">{{ __('Projects') }}</flux:text>
            </div>
            <flux:heading size="xl">{{ $this->projectCount }}</flux:heading>
        </flux:card>

        @foreach ($this->statusCounts as $stat)
            <flux:card class="flex flex-col gap-1">
                <flux:badge size="sm" :color="$stat['status']->color()" :icon="$stat['status']->icon()">
                    {{ $stat['status']->label() }}
                </flux:badge>
                <flux:heading size="xl">{{ $stat['count'] }}</flux:heading>
            </flux:card>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- My tasks (in progress first, then to-do) --}}
        <div class="lg:col-span-1">
            <flux:heading size="lg" class="mb-2">{{ __('My tasks') }}</flux:heading>

            <flux:card class="flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700">
                @forelse ($this->activeTasks as $task)
                    <a
                        href="{{ route('task.show', ['short_name' => $task->project->short_name, 'task_number' => $task->task_number]) }}"
                        wire:navigate
                        class="flex flex-col gap-1.5 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                    >
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" color="indigo" variant="pill">{{ $task->project->short_name }}</flux:badge>
                            <flux:text size="xs" class="font-mono text-zinc-400">{{ $task->reference }}</flux:text>
                        </div>
                        <span class="text-sm">{{ $task->title }}</span>
                        <flux:badge size="sm" :color="$task->status->color()" :icon="$task->status->icon()" class="self-start">
                            {{ $task->status->label() }}
                        </flux:badge>
                    </a>
                @empty
                    <flux:text size="sm" class="px-4 py-6 text-center text-zinc-400">
                        {{ __('Nothing in progress or to do. Enjoy the calm!') }}
                    </flux:text>
                @endforelse
            </flux:card>
        </div>

        {{-- Completion chart for the last two weeks --}}
        <div class="lg:col-span-2">
            <flux:heading size="lg" class="mb-2">{{ __('Completed tasks · last 14 days') }}</flux:heading>

            <flux:card>
                <flux:chart :value="$this->progress" class="aspect-[2/1] w-full">
                    <flux:chart.svg>
                        <flux:chart.bar field="count" class="text-indigo-500 dark:text-indigo-400" radius="4" />

                        <flux:chart.axis axis="x" field="label">
                            <flux:chart.axis.line />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>

                        <flux:chart.axis axis="y" position="left" :tick-values="$this->progressTicks">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                    </flux:chart.svg>
                </flux:chart>
            </flux:card>
        </div>
    </div>

    {{-- Notes --}}
    <div>
        <div class="mb-2 flex items-center justify-between gap-2">
            <flux:heading size="lg">{{ __('Notes') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="$dispatch('open-create-note')" data-test="dashboard-new-note">{{ __('New note') }}</flux:button>
        </div>

        <flux:card class="flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700">
            @forelse ($this->notes as $note)
                <div class="flex items-start justify-between gap-3 px-4 py-3" wire:key="note-{{ $note->id }}" data-test="note-{{ $note->id }}">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <button
                            type="button"
                            wire:click="$dispatch('open-create-note', { noteId: {{ $note->id }} })"
                            class="cursor-pointer truncate text-start text-sm font-medium hover:underline"
                            data-test="edit-note-{{ $note->id }}"
                        >{{ $note->title }}</button>

                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($note->project)
                                <flux:badge size="sm" color="indigo" variant="pill">{{ $note->project->short_name }}</flux:badge>
                            @endif

                            @if ($note->is_public)
                                <flux:badge size="sm" color="green" icon="globe-alt">{{ __('Public') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc" icon="lock-closed">{{ __('Private') }}</flux:badge>
                            @endif

                            @if ($note->convertedTask)
                                <a
                                    href="{{ route('task.show', ['short_name' => $note->convertedTask->project->short_name, 'task_number' => $note->convertedTask->task_number]) }}"
                                    wire:navigate
                                >
                                    <flux:badge size="sm" color="purple" icon="arrow-right-circle">{{ __('Converted → :ref', ['ref' => $note->convertedTask->reference]) }}</flux:badge>
                                </a>
                            @endif
                        </div>
                    </div>

                    <flux:dropdown align="end">
                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Note actions')" data-test="note-actions-{{ $note->id }}" />
                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="$dispatch('open-create-note', { noteId: {{ $note->id }} })">{{ __('Edit') }}</flux:menu.item>
                            @if ($note->project)
                                <flux:menu.item
                                    :icon="$note->is_public ? 'lock-closed' : 'globe-alt'"
                                    wire:click="toggleNoteVisibility({{ $note->id }})"
                                    data-test="toggle-note-visibility-{{ $note->id }}"
                                >
                                    {{ $note->is_public ? __('Make private') : __('Make public') }}
                                </flux:menu.item>
                            @endif
                            <flux:menu.separator />
                            <flux:menu.item
                                icon="trash"
                                variant="danger"
                                wire:click="deleteNote({{ $note->id }})"
                                wire:confirm="{{ __('Delete this note?') }}"
                                data-test="delete-note-{{ $note->id }}"
                            >{{ __('Delete') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <flux:text size="sm" class="px-4 py-6 text-center text-zinc-400">{{ __('No notes yet. Capture an idea to get started.') }}</flux:text>
            @endforelse
        </flux:card>
    </div>
</div>
