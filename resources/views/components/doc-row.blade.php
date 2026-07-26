@props(['doc', 'shortName', 'depth' => 0])

{{--
    A single doc row: a navigate link showing "SHORT-D<n>", the (truncating) title
    and a Draft badge while the doc is unpublished. `depth` indents the row to show
    its place in the doc tree.
--}}
@php($indent = ['ps-4', 'ps-8', 'ps-12', 'ps-16', 'ps-20'][$depth] ?? 'ps-24')

<a
    href="{{ route('doc.show', ['short_name' => $shortName, 'doc_number' => $doc->doc_number]) }}"
    wire:navigate
    wire:key="doc-row-{{ $doc->id }}"
    class="flex items-center justify-between gap-2 {{ $indent }} pe-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800"
    data-test="doc-row-{{ $doc->id }}"
>
    <div class="flex min-w-0 items-center gap-2">
        <flux:icon name="document-text" variant="micro" class="shrink-0 text-zinc-400" />
        <flux:text size="xs" class="font-mono text-zinc-400">{{ $shortName }}-D{{ $doc->doc_number }}</flux:text>
        <span class="truncate text-sm">{{ $doc->title }}</span>
    </div>

    @unless ($doc->is_public)
        <flux:badge size="sm" color="zinc" data-test="doc-draft-badge-{{ $doc->id }}">{{ __('Draft') }}</flux:badge>
    @endunless
</a>
