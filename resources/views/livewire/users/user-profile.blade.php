<div class="flex flex-col gap-6" data-test="user-profile">
    {{-- Header --}}
    <div class="flex items-center gap-4">
        <x-user-avatar :user="$this->user" size="xl" />
        <flux:heading size="xl" data-test="user-profile-name">{{ $this->user->name }}</flux:heading>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Shared projects --}}
        <flux:card class="flex flex-col gap-3" data-test="shared-projects">
            <flux:heading size="sm">{{ __('Shared projects') }}</flux:heading>

            @forelse ($this->sharedProjects as $project)
                <a
                    href="{{ route('project.show', ['short_name' => $project->short_name]) }}"
                    wire:navigate
                    class="flex items-center gap-2 text-sm hover:underline"
                    data-test="shared-project-{{ $project->short_name }}"
                >
                    <flux:badge size="sm" color="zinc">{{ $project->short_name }}</flux:badge>
                    <span class="min-w-0 truncate text-zinc-700 dark:text-zinc-300">{{ $project->title }}</span>
                </a>
            @empty
                <flux:text size="sm" variant="subtle">{{ __('No shared projects yet.') }}</flux:text>
            @endforelse
        </flux:card>

        {{-- Recent activity --}}
        <flux:card class="flex flex-col gap-3" data-test="recent-activity">
            <flux:heading size="sm">{{ __('Recent activity') }}</flux:heading>

            @forelse ($this->activities as $activity)
                @php($url = $this->subjectUrl($activity))
                <div class="flex items-start gap-2 text-sm text-zinc-600 dark:text-zinc-300" wire:key="activity-{{ $activity->id }}">
                    <div class="flex-1">
                        @if ($url)
                            <a href="{{ $url }}" wire:navigate class="font-medium text-zinc-800 hover:underline dark:text-zinc-100">
                                {{ $activity->subject instanceof \App\Models\Task ? $activity->subject->reference : $activity->subject->short_name }}
                            </a>
                        @endif
                        {{ $this->descriptions[$activity->id] }}
                        <span class="text-zinc-400">· <x-relative-time :date="$activity->created_at" /></span>
                    </div>
                </div>
            @empty
                <flux:text size="sm" variant="subtle">{{ __('No recent activity yet.') }}</flux:text>
            @endforelse
        </flux:card>
    </div>
</div>
