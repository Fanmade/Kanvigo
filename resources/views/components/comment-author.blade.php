@props(['comment'])

@php
    // A null relation with a populated user_id means the author's account was
    // removed; distinguish that from genuinely system-authored comments.
    $authorName = $comment->user?->name ?? ($comment->user_id !== null ? __('Deleted user') : __('System'));
@endphp

<div class="flex items-center gap-2">
    <x-user-link :user="$comment->user" class="flex items-center gap-2">
        <x-user-avatar :user="$comment->user" :name="$authorName" />
        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $authorName }}</span>
    </x-user-link>
    <flux:text size="xs" class="text-zinc-400">· <x-relative-time :date="$comment->created_at" /></flux:text>
</div>
