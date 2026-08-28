<div class="app-content mx-auto flex w-full max-w-3xl flex-col gap-6" data-test="waiting-page">
    <div>
        <flux:heading size="xl">{{ __('Waiting on') }}</flux:heading>
        <flux:subheading>{{ __('Work held up on somebody — answer it here, or chase it.') }}</flux:subheading>
    </div>

    {{-- Deliberately a bare <flux:tabs> with no <flux:tab.group>/<flux:tab.panel>:
         Flux shows a panel by matching its `name` against the selected tab
         client-side, and the panel carries `wire:key="{name}"`. Rendering only
         the active scope's panel meant the click first deselected the panel that
         was there, then Livewire swapped in a node with a new key that the tab
         group never selected — a blank page in both directions until a reload
         (KAN-574). `wire:model.live` already round-trips on every switch, so the
         list is simply rendered below the tab strip by the server. --}}
    <div class="flex flex-col gap-4">
        <flux:tabs wire:model.live="tab" variant="segmented" class="max-w-full overflow-x-auto">
            <flux:tab name="on-me" icon="inbox-arrow-down" data-test="tab-on-me">
                {{ __('Waiting on me') }}
                @if ($this->onMeCount > 0)
                    <flux:badge size="sm" color="amber" class="ms-1.5">{{ $this->onMeCount }}</flux:badge>
                @endif
            </flux:tab>
            <flux:tab name="by-me" icon="clock" data-test="tab-by-me">
                {{ __("I'm waiting on") }}
                @if ($this->byMeCount > 0)
                    <flux:badge size="sm" class="ms-1.5">{{ $this->byMeCount }}</flux:badge>
                @endif
            </flux:tab>
        </flux:tabs>

        <div data-test="waiting-list">
            @forelse ($this->groups as $projectId => $tasks)
                @php($project = $tasks->first()->project)

                <div class="mb-6 flex flex-col gap-2" wire:key="waiting-project-{{ $projectId }}">
                    <flux:text size="sm" class="font-medium text-zinc-500 dark:text-zinc-400"
                        >{{ $project->short_name }} · {{ $project->title }}</flux:text>

                    @foreach ($tasks as $task)
                        <flux:card
                            class="flex flex-col gap-3"
                            wire:key="waiting-task-{{ $task->id }}"
                            data-test="waiting-item-{{ $task->id }}"
                        >
                            {{-- Stacks on a phone (where these get answered) and only
                                 puts the meta beside the title from `sm` up. --}}
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex min-w-0 flex-col gap-1">
                                    <a
                                        href="{{ route('task.show', ['short_name' => $project->short_name, 'task_number' => $task->task_number]) }}"
                                        wire:navigate
                                        class="text-sm font-medium hover:underline"
                                    >
                                        <span class="font-mono text-zinc-400">{{ $task->reference }}</span>
                                        {{ $task->title }}
                                    </a>

                                    <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">
                                        @if ($this->scope === \App\Enums\WaitingScope::OnMe)
                                            {{ __('Asked by :name', ['name' => $task->waitingBy?->name ?? __('Someone')]) }}
                                        @else
                                            {{ __('Waiting on :name', ['name' => $task->waitingOn?->name ?? __('Someone')]) }}
                                        @endif
                                        @if ($task->waiting_since)
                                            · {{ $task->waiting_since->diffForHumans() }}
                                        @endif
                                    </flux:text>
                                </div>

                                <div class="shrink-0">
                                    <x-waiting-on-badge :task="$task" />
                                </div>
                            </div>

                            @if (filled($task->description))
                                <x-expandable-description
                                    :content="$task->description"
                                    :short-name="$project->short_name"
                                />
                            @endif

                            @if ($this->commentableProjectIds[$project->id] ?? false)
                                @if ($replyingTo === $task->id)
                                    {{-- The composer is the page's, not the row's: one
                                         answer is written at a time. --}}
                                    <form
                                        wire:submit="reply"
                                        class="flex flex-col gap-2"
                                        data-test="quick-reply-form-{{ $task->id }}"
                                    >
                                        <x-attachments.rich-editor
                                            property="replyBody"
                                            preset="compact"
                                            :placeholder="__('Reply…')"
                                            :mentionables-url="$this->mentionablesUrl"
                                        />

                                        <div class="flex justify-end gap-2">
                                            <flux:button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                wire:click="cancelReply"
                                            >{{ __('Cancel') }}</flux:button>
                                            <flux:button
                                                type="submit"
                                                size="sm"
                                                variant="primary"
                                                icon="paper-airplane"
                                                data-test="quick-reply-send-{{ $task->id }}"
                                            >{{ __('Reply') }}</flux:button>
                                        </div>
                                    </form>
                                @else
                                    <flux:input
                                        as="button"
                                        wire:click="startReply({{ $task->id }})"
                                        size="sm"
                                        icon="chat-bubble-left-right"
                                        :placeholder="__('Reply…')"
                                        :aria-label="__('Reply…')"
                                        data-test="quick-reply-trigger-{{ $task->id }}"
                                    />
                                @endif
                            @endif
                        </flux:card>
                    @endforeach
                </div>
            @empty
                @if ($this->scope === \App\Enums\WaitingScope::OnMe)
                    <x-empty-state icon="check-circle" :heading="__('Nobody is waiting on you')" test="waiting-empty">
                        <flux:text
                            size="sm"
                            class="text-zinc-500"
                        >{{ __('When someone marks a task as waiting on you, it lands here.') }}</flux:text>
                    </x-empty-state>
                @else
                    <x-empty-state icon="clock" :heading="__('You are not waiting on anyone')" test="waiting-empty">
                        <flux:text
                            size="sm"
                            class="text-zinc-500"
                        >{{ __('Mark a task as waiting on someone to chase it from here.') }}</flux:text>
                    </x-empty-state>
                @endif
            @endforelse
        </div>
    </div>
</div>
