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
            {{-- The full editor is heavy (toolbar, min-height, helper text) and pushes
                 existing comments below the fold, so it stays collapsed behind an
                 input-styled trigger until the user clicks to compose. Posting a
                 comment dispatches `comment-added`, which collapses it again. --}}
            <div
                x-data="{ expanded: false }"
                x-on:comment-added.window="expanded = false"
                x-on:open-composer.window="expanded = true; $nextTick(() => { $refs.composer?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); $refs.composer?.querySelector('[contenteditable]')?.focus(); })"
                class="flex flex-col gap-2"
            >
                <flux:input
                    as="button"
                    x-show="!expanded"
                    x-on:click="expanded = true; $nextTick(() => $refs.composer?.querySelector('[contenteditable]')?.focus())"
                    icon="chat-bubble-left-right"
                    :placeholder="__('Write a comment…')"
                    :aria-label="__('Write a comment…')"
                    data-test="comment-composer-trigger"
                />

                <form
                    x-ref="composer"
                    x-show="expanded"
                    x-cloak
                    wire:submit="addComment"
                    class="flex flex-col gap-2"
                >
                    @if ($this->referencedActivityEntries->isNotEmpty())
                        <div class="flex flex-col gap-1.5" data-test="comment-references">
                            <flux:text size="xs" class="text-zinc-500">{{ __('Referencing') }}</flux:text>
                            @foreach ($this->referencedActivityEntries as $entry)
                                <div
                                    class="flex items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-xs dark:border-white/10 dark:bg-zinc-700/40"
                                    wire:key="reference-{{ $entry->reference }}"
                                    data-test="comment-reference"
                                >
                                    <div class="flex-1 text-zinc-600 dark:text-zinc-300">
                                        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $entry->user?->name ?? __('System') }}</span>
                                        {{ \App\Support\ActivityDescriber::describe($entry) }}
                                        <span class="text-zinc-400">· {{ $entry->reference }}</span>
                                    </div>
                                    <flux:button
                                        type="button"
                                        size="xs"
                                        variant="ghost"
                                        icon="x-mark"
                                        wire:click="removeReference('{{ $entry->reference }}')"
                                        :aria-label="__('Remove reference')"
                                        data-test="remove-reference"
                                    />
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <x-attachments.rich-editor property="body" toolbar="bold italic strike | bullet ordered | link" :placeholder="__('Write a comment…')" :mentionables-url="$this->mentionablesUrl" />
                    <div class="flex justify-end gap-2">
                        <flux:button type="button" size="sm" variant="ghost" x-on:click="expanded = false">
                            {{ __('Cancel') }}
                        </flux:button>
                        <flux:button type="submit" size="sm" variant="primary" icon="chat-bubble-left-right" data-test="add-comment">
                            {{ __('Comment') }}
                        </flux:button>
                    </div>
                </form>
            </div>

            <div class="flex flex-col gap-3">
                @forelse ($this->comments as $comment)
                    @php($threadIds = $comment->replies->pluck('id')->push($comment->id))
                    <flux:card class="flex flex-col gap-3" wire:key="comment-{{ $comment->id }}">
                        <x-comment :comment="$comment" :editing-id="$editingId" :confirming-delete="$confirmingDelete" :mentionables-url="$this->mentionablesUrl" />

                        @foreach ($comment->replies as $reply)
                            <div class="ms-6 border-s-2 border-zinc-100 ps-3 dark:border-zinc-700" wire:key="reply-{{ $reply->id }}">
                                <x-comment :comment="$reply" :editing-id="$editingId" :confirming-delete="$confirmingDelete" :mentionables-url="$this->mentionablesUrl" />
                            </div>
                        @endforeach

                        @if ($threadIds->contains($replyingTo))
                            <form wire:submit="addReply" class="ms-6 flex flex-col gap-2">
                                <x-attachments.rich-editor property="replyBody" toolbar="bold italic strike | bullet ordered | link" :placeholder="__('Write a reply…')" :mentionables-url="$this->mentionablesUrl" />
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

                @if ($this->hasMoreComments)
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        wire:click="showMore"
                        class="self-center"
                        data-test="show-more-comments"
                    >
                        {{ __('Show older comments') }}
                    </flux:button>
                @endif
            </div>
        </div>
    @endunless
</div>
