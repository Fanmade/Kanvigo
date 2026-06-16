@props(['comment'])

<div class="flex items-center gap-2">
    <flux:avatar size="xs" :name="$comment->user?->name ?? __('System')" />
    <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $comment->user?->name ?? __('System') }}</span>
    <flux:text size="xs" class="text-zinc-400">· {{ $comment->created_at?->diffForHumans() }}</flux:text>
</div>
