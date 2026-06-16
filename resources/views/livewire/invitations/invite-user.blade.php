<div>
    <div class="mx-auto flex w-full max-w-lg flex-col gap-6">
        <div>
            <flux:heading size="xl">{{ __('Invite a user') }}</flux:heading>
            <flux:subheading>{{ __('Send a secure invitation link by email. You can only grant access to projects you belong to.') }}</flux:subheading>
        </div>

        <form wire:submit="sendInvitation" class="flex flex-col gap-4">
            <flux:input type="email" wire:model="email" :label="__('Email address')" required />

            <flux:field>
                <flux:label>{{ __('Grant access to projects') }}</flux:label>
                <flux:select variant="listbox" multiple wire:model="projectIds" :placeholder="__('Select projects')">
                    @foreach ($this->inviterProjects as $project)
                        <flux:select.option :value="$project->id">{{ $project->short_name }} · {{ $project->title }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="projectIds" />
            </flux:field>

            <flux:button type="submit" variant="primary">{{ __('Send invitation') }}</flux:button>
        </form>
    </div>
</div>
