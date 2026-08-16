<div class="flex w-full flex-col gap-6" data-test="global-activity-feed">
    <flux:heading size="xl">{{ __('Activity') }}</flux:heading>

    @forelse ($this->days as $day => $entries)
        <section class="flex flex-col gap-3" wire:key="day-{{ $day }}">
            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="activity-day">
                {{ $this->dayLabel($day) }}
            </flux:heading>

            <flux:card class="p-0">
                <ul class="divide-y divide-zinc-200/70 dark:divide-white/10">
                    @foreach ($entries as $activity)
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
                {{ __('Nothing has happened yet in the projects you can see.') }}
            </flux:text>
        </flux:card>
    @endforelse

    <flux:pagination :paginator="$this->activities" />
</div>
