<div class="flex flex-col gap-4" data-test="project-roles">
    <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10" data-test="roles-list">
        @foreach ($this->roles as $role)
            <div class="flex items-start justify-between gap-3 p-3" wire:key="role-{{ $role->id }}" data-test="role-row-{{ $role->id }}">
                <div class="min-w-0">
                    <flux:heading size="sm">{{ $role->name }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">
                        {{ implode(', ', $this->permissionsByRole[$role->id] ?? []) ?: __('No permissions') }}
                    </flux:text>
                </div>

                @unless (in_array($role->name, ['owner', 'admin', 'member'], true))
                    <flux:button
                        size="xs"
                        variant="ghost"
                        icon="trash"
                        :aria-label="__('Delete role')"
                        wire:click="deleteRole({{ $role->id }})"
                        wire:confirm="{{ __('Delete this role?') }}"
                        data-test="delete-role-{{ $role->id }}"
                    />
                @endunless
            </div>
        @endforeach
    </div>

    <form wire:submit="createRole" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10" data-test="create-role-form">
        <flux:heading size="sm">{{ __('Add a custom role') }}</flux:heading>

        <flux:input wire:model="name" :label="__('Name')" data-test="role-name" />

        <flux:field>
            <flux:label>{{ __('Permissions') }}</flux:label>
            <flux:checkbox.group wire:model="permissionIds" class="grid grid-cols-2 gap-1">
                @foreach ($this->permissions as $permission)
                    <flux:checkbox value="{{ $permission->id }}" :label="$permission->name" data-test="role-permission-{{ $permission->name }}" />
                @endforeach
            </flux:checkbox.group>
            <flux:description>{{ __('A custom role can hold any of the project permissions.') }}</flux:description>
        </flux:field>

        <flux:button type="submit" variant="primary" data-test="save-role">{{ __('Add role') }}</flux:button>
    </form>
</div>
