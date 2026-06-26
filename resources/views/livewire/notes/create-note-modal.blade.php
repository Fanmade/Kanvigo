<div>
    <flux:modal wire:model.self="show" wire:close="close" class="w-full max-w-2xl" data-test="create-note-modal">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">
                {{ $noteId && ! $draft ? __('Edit note') : __('New note') }}
            </flux:heading>

            <flux:input wire:model="title" :label="__('Title')" data-test="create-note-title" />

            <x-attachments.rich-editor
                property="body"
                :label="__('Note')"
                toolbar="bold italic strike | bullet ordered | link"
                :placeholder="__('Write your note…')"
            />

            {{-- Optional project attachment + visibility. Public is only available
                 once a project is chosen and resets when it is cleared. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select
                    wire:model.live="projectId"
                    :label="__('Project')"
                    data-test="create-note-project"
                >
                    <flux:select.option value="">{{ __('None (private note)') }}</flux:select.option>
                    @foreach ($this->projects as $project)
                        <flux:select.option :value="$project->id">{{ $project->short_name }} · {{ $project->title }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex flex-col justify-end gap-1">
                    <flux:switch
                        wire:model.live="isPublic"
                        :label="__('Make public to the project')"
                        :disabled="$projectId === null"
                        align="left"
                        data-test="create-note-public"
                    />
                    @if ($projectId === null)
                        <flux:text size="sm" class="text-zinc-400">{{ __('Attach a project to share this note.') }}</flux:text>
                    @endif
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="create-note-submit">{{ __('Save note') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
