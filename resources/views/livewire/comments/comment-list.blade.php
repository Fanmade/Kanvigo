<div class="flex flex-col gap-3">
    <button
        type="button"
        wire:click="toggleCollapsed"
        class="flex items-center gap-2 text-start"
        aria-expanded="{{ $collapsed ? 'false' : 'true' }}"
        aria-controls="comments-body-{{ $morphSubjectId }}"
    >
        <flux:icon :name="$collapsed ? 'chevron-right' : 'chevron-down'" variant="micro" class="text-zinc-400" />
        <flux:heading size="sm">{{ __('Comments') }}</flux:heading>
        <flux:badge size="sm" color="zinc">{{ $this->commentCount }}</flux:badge>
    </button>

    @unless ($collapsed)
        <div id="comments-body-{{ $morphSubjectId }}" class="flex flex-col gap-3">
            <form wire:submit="addComment" class="flex flex-col gap-2">
                <x-attachments.rich-editor property="body" toolbar="bold italic strike | bullet ordered | link" :placeholder="__('Write a comment…')" />
                <div class="flex justify-end">
                    <flux:button type="submit" size="sm" variant="primary" icon="chat-bubble-left-right">
                        {{ __('Comment') }}
                    </flux:button>
                </div>
            </form>

            <div class="flex flex-col gap-3">
                @forelse ($this->comments as $comment)
                    @php($threadIds = $comment->replies->pluck('id')->push($comment->id))
                    <flux:card class="flex flex-col gap-3" wire:key="comment-{{ $comment->id }}">
                        <x-comment :comment="$comment" :editing-id="$editingId" :confirming-delete="$confirmingDelete" />

                        @foreach ($comment->replies as $reply)
                            <div class="ms-6 border-s-2 border-zinc-100 ps-3 dark:border-zinc-700" wire:key="reply-{{ $reply->id }}">
                                <x-comment :comment="$reply" :editing-id="$editingId" :confirming-delete="$confirmingDelete" />
                            </div>
                        @endforeach

                        @if ($threadIds->contains($replyingTo))
                            <form wire:submit="addReply" class="ms-6 flex flex-col gap-2">
                                <x-attachments.rich-editor property="replyBody" toolbar="bold italic strike | bullet ordered | link" :placeholder="__('Write a reply…')" />
                                <div class="flex justify-end gap-2">
                                    <flux:button type="button" size="sm" variant="ghost" wire:click="cancelReply">{{ __('Cancel') }}</flux:button>
                                    <flux:button type="submit" size="sm" variant="primary">{{ __('Reply') }}</flux:button>
                                </div>
                            </form>
                        @endif
                    </flux:card>
                @empty
                    <flux:text size="sm" class="text-zinc-400">{{ __('No comments yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    @endunless
</div>
