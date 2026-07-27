@props(['item'])

{{--
    A cross-referenced item — a task or a doc — as a compact navigate link showing
    its reference and title. Used by the doc page's links and backlinks panels.
--}}
@php
    $isDoc = $item instanceof \App\Models\Doc;

    $url = $isDoc
        ? route('doc.show', ['short_name' => $item->project->short_name, 'doc_number' => $item->doc_number])
        : route('task.show', ['short_name' => $item->project->short_name, 'task_number' => $item->task_number]);
@endphp

<a
    href="{{ $url }}"
    wire:navigate
    data-test="reference-item-{{ $item->reference }}"
    {{ $attributes->merge(['class' => 'flex min-w-0 items-center gap-2 rounded px-1 py-0.5 hover:bg-zinc-50 dark:hover:bg-zinc-800']) }}
>
    <flux:icon :name="$isDoc ? 'document-text' : 'rectangle-stack'" variant="micro" class="shrink-0 text-zinc-400" />
    <flux:text size="xs" class="font-mono text-zinc-400">{{ $item->reference }}</flux:text>
    <span class="truncate text-sm">{{ $item->title }}</span>
</a>
