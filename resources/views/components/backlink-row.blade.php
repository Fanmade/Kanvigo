@php use App\Models\Doc; @endphp
@props(['item'])

{{--
    A single "referenced by" row: the task or doc that links here, as a navigate
    link showing its reference, title and — for a task — its status. Richer than
    the compact {@see x-reference-item} used in the rails, because this row is the
    reader's way back to wherever the doc was cited.
--}}
@php
    /* @var \App\Models\Doc|\App\Models\Task $item */
    $isDoc = $item instanceof Doc;

    $url = $isDoc
        ? route('doc.show', ['short_name' => $item->project->short_name, 'doc_number' => $item->doc_number])
        : route('task.show', ['short_name' => $item->project->short_name, 'task_number' => $item->task_number]);
@endphp

<a
    href="{{ $url }}"
    wire:navigate
    wire:key="backlink-{{ $item->getMorphClass() }}-{{ $item->getKey() }}"
    class="flex items-center justify-between gap-2 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800"
    data-test="backlink-{{ $item->reference }}"
>
    <div class="flex min-w-0 items-center gap-2">
        <flux:icon
            :name="$isDoc ? 'document-text' : 'rectangle-stack'"
            variant="micro"
            class="shrink-0 text-zinc-400"
        />
        <flux:text size="xs" class="font-mono text-zinc-400">{{ $item->reference }}</flux:text>
        <span class="truncate text-sm">{{ $item->title }}</span>
    </div>

    @if ($isDoc)
        @unless ($item->is_public)
            <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
        @endunless
    @else
        <flux:badge
            size="sm"
            :color="$item->status->color()"
            :icon="$item->status->icon()"
        >{{ $item->status->label() }}</flux:badge>
    @endif
</a>
