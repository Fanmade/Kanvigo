{{--
    The cross-reference panels for a task or doc rail: the items it links to
    (written as #references in its rich text, or linked through the API), and the
    items linking back to it. Both lists only ever show what the reader may open.
--}}
<div class="flex flex-col gap-2" data-test="item-links">
    <flux:heading size="sm">{{ __('Links') }}</flux:heading>

    @forelse ($this->linkedItems as $item)
        <x-reference-item :item="$item" wire:key="link-{{ $item->getMorphClass() }}-{{ $item->getKey() }}" />
    @empty
        <flux:text size="sm" class="text-zinc-400">{{ __('No linked items yet.') }}</flux:text>
    @endforelse
</div>

<div class="flex flex-col gap-2" data-test="item-backlinks">
    <flux:heading size="sm">{{ __('Linked from') }}</flux:heading>

    @forelse ($this->backlinks as $item)
        <x-reference-item :item="$item" wire:key="backlink-{{ $item->getMorphClass() }}-{{ $item->getKey() }}" />
    @empty
        <flux:text size="sm" class="text-zinc-400">{{ __('Nothing links here yet.') }}</flux:text>
    @endforelse
</div>
