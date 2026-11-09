<div class="flex w-full flex-col gap-6" data-test="global-activity-feed">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Activity') }}</flux:heading>

        {{-- Two views over the same filters: every change, or one row per task. --}}
        <flux:tabs wire:model.live="view" variant="segmented" data-test="activity-view">
            <flux:tab name="events" icon="queue-list" data-test="view-events">{{ __('Events') }}</flux:tab>
            <flux:tab name="tasks" icon="rectangle-stack" data-test="view-tasks">{{ __('Tasks') }}</flux:tab>
        </flux:tabs>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Task')"
            icon="magnifying-glass"
            size="sm"
            class="max-w-56"
            :placeholder="__('Reference or title')"
            data-test="filter-search"
        />

        <flux:select wire:model.live="actor" :label="__('Person')" size="sm" class="max-w-56" data-test="filter-actor">
            <flux:select.option value="">{{ __('Everyone') }}</flux:select.option>
            @foreach ($this->actors as $actor)
                <flux:select.option value="{{ $actor->public_id }}">{{ $actor->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select
            wire:model.live="project"
            :label="__('Project')"
            size="sm"
            class="max-w-56"
            data-test="filter-project"
        >
            <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
            @foreach ($this->projects as $filterProject)
                <flux:select.option value="{{ $filterProject->short_name }}">
                    {{ $filterProject->short_name }} · {{ $filterProject->title }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:select
            wire:model.live="category"
            :label="__('Type')"
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

        <flux:select
            wire:model.live="source"
            :label="__('Source')"
            size="sm"
            class="max-w-48"
            data-test="filter-source"
        >
            <flux:select.option value="all">{{ __('All sources') }}</flux:select.option>
            <flux:select.option value="people">{{ __('People') }}</flux:select.option>
            <flux:select.option value="agents">{{ __('Agents & API') }}</flux:select.option>
        </flux:select>

        <flux:select
            wire:model.live="status"
            :label="__('Status')"
            size="sm"
            class="max-w-40"
            data-test="filter-status"
        >
            <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
            @foreach (\App\Enums\Status::cases() as $statusOption)
                <flux:select.option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->tagOptions !== [])
            <flux:select wire:model.live="tag" :label="__('Tag')" size="sm" class="max-w-48" data-test="filter-tag">
                <flux:select.option value="">{{ __('Any tag') }}</flux:select.option>
                @foreach ($this->tagOptions as $tagOption)
                    <flux:select.option value="{{ $tagOption }}">{{ $tagOption }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:switch wire:model.live="mine" :label="__('Include my own')" data-test="filter-mine" />

        @if ($this->isFiltered)
            <flux:button
                size="sm"
                variant="ghost"
                wire:click="clearFilters"
                data-test="clear-filters"
            >{{ __('Clear filters') }}</flux:button>
        @endif
    </div>

    @if ($view === 'tasks')
        @if ($this->updatedTasks->isEmpty())
            <flux:card>
                <flux:text class="text-zinc-500" data-test="activity-empty">
                    @if ($this->isFiltered)
                        {{ __('No activity matches these filters.') }}
                    @else
                        {{ __('Nothing has happened yet in the projects you can see.') }}
                    @endif
                </flux:text>
            </flux:card>
        @else
            <flux:card class="p-0">
                <ul class="divide-y divide-zinc-200/70 dark:divide-white/10" data-test="updated-tasks">
                    @foreach ($this->updatedTasks as $activity)
                        @php($task = $activity->subject)
                        <li
                            class="flex items-start gap-3 px-4 py-3 text-sm"
                            wire:key="updated-task-{{ $activity->subject_id }}"
                            data-test="updated-task-row"
                        >
                            <x-user-link :user="$activity->user">
                                <x-user-avatar :user="$activity->user" :name="$activity->user?->name ?? __('System')" />
                            </x-user-link>

                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                    @if ($this->isNew($activity))
                                        <span
                                            class="size-2 shrink-0 rounded-full bg-amber-500"
                                            title="{{ __('New since your last visit') }}"
                                            data-test="updated-task-new"
                                        ></span>
                                    @endif
                                    <a
                                        href="{{ $this->taskUrl($activity) }}"
                                        wire:navigate
                                        class="min-w-0 truncate font-medium text-zinc-800 hover:underline dark:text-zinc-100"
                                        data-test="updated-task-link"
                                    >
                                        <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ $task->reference }}</span>
                                        {{ $task->title }}
                                    </a>
                                    <x-status-badge :status="$task->status" />
                                </div>

                                <div class="text-zinc-500 dark:text-zinc-400" data-test="updated-task-latest">
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $activity->user?->name ?? __('System') }}</span>
                                    {{ $this->descriptions[$activity->id] }}
                                    · <x-relative-time :date="$activity->created_at" />
                                    @if ($activity->token_name)
                                        <span data-test="activity-source">· {{ __('via API token') }}</span>
                                    @endif
                                </div>
                            </div>

                            @if ($activity->project)
                                <x-project-badge :project="$activity->project" size="sm" class="shrink-0" />
                            @endif
                        </li>
                    @endforeach
                </ul>
            </flux:card>

            <flux:pagination :paginator="$this->updatedTasks" />
        @endif
    @else
        @forelse ($this->days as $day => $entries)
            <section class="flex flex-col gap-3" wire:key="day-{{ $day }}">
                <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="activity-day">
                    {{ $this->dayLabel($day) }}
                </flux:heading>

                <flux:card class="p-0">
                    <ul class="divide-y divide-zinc-200/70 dark:divide-white/10">
                        @foreach ($entries as $activity)
                            @if ($activity->id === $this->firstSeenId)
                                <li class="flex items-center gap-3 px-4 py-2" data-test="new-since-divider">
                                    <span class="h-px flex-1 bg-amber-300 dark:bg-amber-400/40"></span>
                                    <flux:text size="sm" class="shrink-0 text-amber-600 dark:text-amber-400">
                                        {{ __('New since your last visit') }}
                                    </flux:text>
                                    <span class="h-px flex-1 bg-amber-300 dark:bg-amber-400/40"></span>
                                </li>
                            @endif

                            <li
                                class="flex items-start gap-2 px-4 py-2.5 text-sm"
                                wire:key="activity-{{ $activity->id }}"
                                data-test="activity-row"
                            >
                                <x-user-link :user="$activity->user">
                                    <x-user-avatar :user="$activity->user" :name="$activity->user?->name ?? __('System')" />
                                </x-user-link>

                                <div class="min-w-0 flex-1 text-zinc-600 dark:text-zinc-300">
                                    <x-user-link
                                        :user="$activity->user"
                                        class="font-medium text-zinc-800 dark:text-zinc-100"
                                    >{{ $activity->user?->name ?? __('System') }}</x-user-link>
                                    {{ $this->descriptions[$activity->id] }}

                                    @php($url = $this->rowUrl($activity))
                                    @if ($url)
                                        <a
                                            href="{{ $url }}"
                                            wire:navigate
                                            class="font-medium text-blue-600 underline-offset-2 hover:underline dark:text-blue-400"
                                            data-test="activity-subject"
                                        >{{ $this->subjectLabel($activity) }}</a>
                                    @else
                                        <span class="text-zinc-500">{{ $this->subjectLabel($activity) }}</span>
                                    @endif

                                    <span class="text-zinc-400"> · <x-relative-time :date="$activity->created_at" /> </span>

                                    @if ($activity->token_name)
                                        <span class="text-zinc-400" data-test="activity-source"
                                            >· {{ __('via API token') }}</span>
                                    @endif
                                </div>

                                @if ($activity->project)
                                    <x-project-badge :project="$activity->project" size="sm" class="shrink-0" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </flux:card>
            </section>
        @empty
            <flux:card>
                <flux:text class="text-zinc-500" data-test="activity-empty">
                    @if ($this->isFiltered)
                        {{ __('No activity matches these filters.') }}
                    @else
                        {{ __('Nothing has happened yet in the projects you can see.') }}
                    @endif
                </flux:text>
            </flux:card>
        @endforelse

        <flux:pagination :paginator="$this->activities" />
    @endif
</div>
