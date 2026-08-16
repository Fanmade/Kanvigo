<div class="flex w-full flex-col gap-6" data-test="global-activity-feed">
    <flux:heading size="xl">{{ __('Activity') }}</flux:heading>

    <div class="flex flex-wrap items-end gap-3">
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
</div>
