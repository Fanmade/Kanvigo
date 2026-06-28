<div>
    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('Projects') }}</flux:heading>

            @can('create-projects')
                <flux:button variant="primary" icon="plus" wire:click="$set('showCreate', true)" data-test="create-project">
                    {{ __('New project') }}
                </flux:button>
            @endcan
        </div>

        @if ($this->projects->isEmpty())
            <flux:card class="text-center">
                <flux:text>{{ __('You are not a member of any projects yet.') }}</flux:text>
            </flux:card>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->projects as $project)
                    <a href="{{ route('project.show', $project) }}" wire:navigate class="block min-w-0">
                        <flux:card class="h-full transition hover:shadow-md">
                            <div class="flex min-w-0 items-center gap-2">
                                <flux:badge color="indigo" size="sm">{{ $project->short_name }}</flux:badge>
                                <flux:heading size="lg" class="min-w-0 truncate">{{ $project->title }}</flux:heading>
                            </div>
                            @if ($project->description)
                                {{-- Render the stored rich-text description (sanitized) rather than
                                     dumping its raw markup, clamped to a few lines so cards stay even. --}}
                                <x-rich-text
                                    :content="$project->description"
                                    :short-name="$project->short_name"
                                    class="mt-2 line-clamp-3 text-sm break-words text-zinc-500 [&_*]:my-0 dark:text-zinc-400"
                                    data-test="project-card-description"
                                />
                            @endif
                        </flux:card>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @can('create-projects')
    <flux:modal wire:model="showCreate" class="md:w-96">
        <form wire:submit="createProject" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('New project') }}</flux:heading>

            <flux:input wire:model.blur.live="title" :label="__('Title')" data-test="project-title" />

            <flux:input
                wire:model="short_name"
                :label="__('Short name')"
                :description="__('2-4 letters, e.g. ABC')"
                maxlength="4"
                class="uppercase"
                data-test="project-short-name"
            />

            <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan
</div>
