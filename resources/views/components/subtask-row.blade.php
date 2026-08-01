@props(['task', 'shortName', 'test', 'padding' => 'px-4 py-3'])

{{--
    A single subtask row: a navigate link showing "SHORT-N", the (truncating) title
    greyed when archived, and a status badge. Shared by the task detail subtask list
    and the root-task card. `test` is the data-test prefix ("{test}-{id}"); `padding`
    lets each context set its own row padding.
--}}
<a
    href="{{ route('task.show', ['short_name' => $shortName, 'task_number' => $task->task_number]) }}"
    wire:navigate
    class="flex items-center justify-between gap-2 {{ $padding }} hover:bg-zinc-50 dark:hover:bg-zinc-800"
    data-test="{{ $test }}-{{ $task->id }}"
>
    <div class="flex min-w-0 items-center gap-2">
        <flux:text size="xs" class="font-mono text-zinc-400">{{ $shortName }}-{{ $task->task_number }}</flux:text>
        <span @class(['truncate text-sm', 'text-zinc-400' => $task->isArchived()])>{{ $task->title }}</span>
    </div>
    <flux:badge
        size="sm"
        :color="$task->status->color()"
        :icon="$task->status->icon()"
    >{{ $task->status->label() }}</flux:badge>
</a>
