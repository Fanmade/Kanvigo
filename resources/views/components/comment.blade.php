@props(['comment', 'editingId' => null, 'confirmingDelete' => null, 'mentionablesUrl' => null, 'shortName' => null])

<div class="flex flex-col gap-1">
    <div class="flex items-center justify-between gap-2">
        <x-comment-author :comment="$comment" />

        @if ($editingId !== $comment->id && $confirmingDelete !== $comment->id)
            <div class="flex items-center gap-0.5">
                <flux:tooltip :content="__('Reply')">
                    <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="startReply({{ $comment->id }})" />
                </flux:tooltip>

                @unless ($comment->is_deleted)
                    @can('update', $comment)
                        <flux:tooltip :content="__('Edit')">
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="startEdit({{ $comment->id }})" />
                        </flux:tooltip>
                    @endcan
                    @can('delete', $comment)
                        <flux:tooltip :content="__('Delete')">
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="confirmDelete({{ $comment->id }})" />
                        </flux:tooltip>
                    @endcan
                @endunless
            </div>
        @endif
    </div>

    @if ($comment->is_deleted)
        <flux:text size="sm" class="italic text-zinc-400">{{ __('deleted') }}</flux:text>
        @if ($comment->delete_reason)
            <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $comment->delete_reason }}</flux:text>
        @endif
    @elseif ($editingId === $comment->id)
        <form wire:submit="updateComment" class="flex flex-col gap-2">
            <x-attachments.rich-editor property="editBody" toolbar="bold italic strike | bullet ordered blockquote | link | align ~ undo redo" :mentionables-url="$mentionablesUrl" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    @elseif ($confirmingDelete === $comment->id)
        <div class="flex flex-col gap-2 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900/50 dark:bg-red-950/30">
            <flux:text size="sm">{{ __('Delete this comment?') }}</flux:text>
            <flux:input wire:model="deleteReason" :placeholder="__('Optional reason (shown if the comment has replies)')" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" size="sm" variant="ghost" wire:click="cancelDelete">{{ __('Cancel') }}</flux:button>
                <flux:button type="button" size="sm" variant="danger" wire:click="deleteComment">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    @else
        <x-rich-text :content="$comment->body" :short-name="$shortName" class="text-sm" />

        @if ($comment->activities->isNotEmpty())
            <div class="mt-1 flex flex-col gap-1" data-test="comment-activity-references">
                @foreach ($comment->activities as $activity)
                    <a
                        href="{{ $activity->deepLinkUrl() }}"
                        wire:navigate
                        class="flex items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-xs text-zinc-600 transition-colors hover:border-zinc-300 dark:border-white/10 dark:bg-zinc-700/40 dark:text-zinc-300 dark:hover:border-white/20"
                        wire:key="comment-{{ $comment->id }}-ref-{{ $activity->id }}"
                        data-test="comment-activity-reference"
                    >
                        <flux:icon name="clock" variant="micro" class="mt-0.5 shrink-0 text-zinc-400" />
                        <span class="flex-1">
                            <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $activity->user?->name ?? __('System') }}</span>
                            {{ \App\Support\ActivityDescriber::describe($activity) }}
                            <span class="text-zinc-400">· {{ $activity->reference }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</div>
