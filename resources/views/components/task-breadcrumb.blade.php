@props(['task'])

{{--
    The task's place in the tree, as a row of clickable badges: each open ancestor
    from the root down, then the task itself. A root task shows only its own badge.
    Every node in the same project shares the project short name, so each badge's
    flat id is built from it plus the node's task number — no per-ancestor query.
--}}
@php
    $shortName = $task->story->project->short_name;
    $ancestors = $task->ancestors->sortBy($task->getDepthName())->values();
@endphp

<div class="flex min-w-0 flex-wrap items-center gap-x-1 gap-y-0.5">
    @foreach ($ancestors as $ancestor)
        <flux:tooltip :content="$ancestor->title">
            <a
                href="{{ route('task.show', ['short_name' => $shortName, 'task_number' => $ancestor->task_number]) }}"
                wire:navigate
                class="w-fit"
                data-test="crumb-{{ $task->id }}-{{ $ancestor->id }}"
            >
                <flux:badge size="sm" color="zinc">{{ $shortName }}-{{ $ancestor->task_number }}</flux:badge>
            </a>
        </flux:tooltip>
        <flux:icon.chevron-right variant="micro" class="text-zinc-300 dark:text-zinc-600" />
    @endforeach

    <flux:tooltip :content="$task->title">
        <a
            href="{{ route('task.show', ['short_name' => $shortName, 'task_number' => $task->task_number]) }}"
            wire:navigate
            class="w-fit"
            data-test="crumb-{{ $task->id }}-self"
        >
            <flux:badge size="sm" color="zinc">{{ $task->reference }}</flux:badge>
        </a>
    </flux:tooltip>
</div>
