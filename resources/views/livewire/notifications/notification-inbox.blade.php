<div class="flex flex-col gap-4" data-test="notification-inbox">
    <div class="flex flex-wrap items-end gap-3">
        <flux:select
            wire:model.live="status"
            :label="__('Status')"
            size="sm"
            class="max-w-40"
            data-test="filter-status"
        >
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="unread">{{ __('Unread') }}</flux:select.option>
            <flux:select.option value="read">{{ __('Read') }}</flux:select.option>
        </flux:select>

        <flux:select
            wire:model.live="project"
            :label="__('Project')"
            size="sm"
            class="max-w-56"
            data-test="filter-project"
        >
            <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
            @foreach ($this->projects as $project)
                <flux:select.option value="{{ $project->short_name }}">
                    {{ $project->short_name }} · {{ $project->title }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:select
            wire:model.live="category"
            :label="__('Activity')"
            size="sm"
            class="max-w-48"
            data-test="filter-category"
        >
            <flux:select.option value="all">{{ __('All activity') }}</flux:select.option>
            @foreach (array_keys($this::ACTION_CATEGORIES) as $category)
                <flux:select.option value="{{ $category }}">{{ $this->categoryLabel($category) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="range" :label="__('Period')" size="sm" class="max-w-40" data-test="filter-range">
            <flux:select.option value="all">{{ __('Any time') }}</flux:select.option>
            <flux:select.option value="today">{{ __('Today') }}</flux:select.option>
            <flux:select.option value="week">{{ __('Last 7 days') }}</flux:select.option>
            <flux:select.option value="month">{{ __('Last 30 days') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <flux:button
            size="sm"
            variant="ghost"
            wire:click="toggleSelectPage"
            data-test="toggle-select-page"
        >{{ __('Select page') }}</flux:button>

        @if ($this->selected !== [])
            <flux:text size="sm" class="text-zinc-500" data-test="selected-count">
                {{ __(':count selected', ['count' => count($this->selected)]) }}
            </flux:text>

            <flux:button
                size="sm"
                variant="ghost"
                icon="envelope-open"
                wire:click="markSelectedRead"
                data-test="bulk-mark-read"
            >{{ __('Mark read') }}</flux:button>

            <flux:button
                size="sm"
                variant="ghost"
                icon="envelope"
                wire:click="markSelectedUnread"
                data-test="bulk-mark-unread"
            >{{ __('Mark unread') }}</flux:button>

            <flux:button
                size="sm"
                variant="ghost"
                icon="x-mark"
                wire:click="dismissSelected"
                data-test="bulk-dismiss"
            >{{ __('Dismiss') }}</flux:button>
        @endif
    </div>

    <div class="flex flex-col gap-2">
        @forelse ($this->notifications as $notification)
            @php($data = $notification->data)
            <flux:card
                class="flex items-start gap-3 py-3"
                wire:key="inbox-{{ $notification->id }}"
                data-test="inbox-notification-{{ $notification->id }}"
            >
                <flux:checkbox
                    wire:model.live="selected"
                    value="{{ $notification->id }}"
                    class="mt-1"
                    :aria-label="__('Select notification')"
                    data-test="select-notification-{{ $notification->id }}"
                />

                <button
                    type="button"
                    class="min-w-0 flex-1 cursor-pointer text-left"
                    wire:click="open('{{ $notification->id }}')"
                    data-test="open-notification-{{ $notification->id }}"
                >
                    <span class="block text-sm {{ $notification->read_at ? 'text-zinc-500 dark:text-zinc-400' : 'font-medium' }}">
                        <span>{{ $data['actor'] ?? __('System') }}</span>
                        {{ $this->actionLabel($data['action'] ?? '') }}
                        <span class="font-mono text-xs">{{ $data['reference'] }}</span>
                        @if (! empty($data['title']))
                            <span class="text-zinc-500">· {{ $data['title'] }}</span>
                        @endif
                    </span>
                    <span class="block text-xs text-zinc-400"
                        ><x-relative-time :date="$notification->created_at"
                    /></span>
                </button>

                <div class="flex shrink-0 items-center gap-1">
                    @if ($notification->read_at)
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="envelope"
                            :aria-label="__('Mark unread')"
                            wire:click="markUnread('{{ $notification->id }}')"
                            data-test="mark-unread-{{ $notification->id }}"
                        />
                    @else
                        <flux:button
                            size="xs"
                            variant="ghost"
                            icon="envelope-open"
                            :aria-label="__('Mark read')"
                            wire:click="markRead('{{ $notification->id }}')"
                            data-test="mark-read-{{ $notification->id }}"
                        />
                    @endif

                    <flux:button
                        size="xs"
                        variant="ghost"
                        icon="x-mark"
                        :aria-label="__('Dismiss notification')"
                        wire:click="dismiss('{{ $notification->id }}')"
                        data-test="inbox-dismiss-{{ $notification->id }}"
                    />
                </div>
            </flux:card>
        @empty
            <flux:card class="py-10 text-center" data-test="inbox-empty">
                <flux:text class="text-zinc-400">{{ __('Nothing here. Notifications you receive show up in this list.') }}</flux:text>
            </flux:card>
        @endforelse
    </div>

    <flux:pagination :paginator="$this->notifications" />
</div>
