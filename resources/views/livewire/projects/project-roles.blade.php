<div class="flex flex-col gap-4" data-test="project-roles">
    <div class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10" data-test="roles-list">
        @foreach ($this->roles as $role)
            <div class="flex flex-col gap-3 p-3" wire:key="role-{{ $role->id }}" data-test="role-row-{{ $role->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <flux:heading size="sm">{{ $role->name }}</flux:heading>
                        <flux:text size="sm" class="mt-1 text-zinc-500">
                            {{ collect($this->permissionsByRole[$role->id] ?? [])->map(fn (string $name) => $this->permissionLabel($name))->implode(', ') ?: __('No permissions') }}
                        </flux:text>
                    </div>

                    @if ($this->editableRoleIds->contains($role->id))
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Edit role')"
                                wire:click="startEdit({{ $role->id }})"
                                data-test="edit-role-{{ $role->id }}"
                            />
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="trash"
                                :aria-label="__('Delete role')"
                                wire:click="deleteRole({{ $role->id }})"
                                wire:confirm="{{ __('Delete this role?') }}"
                                data-test="delete-role-{{ $role->id }}"
                            />
                        </div>
                    @endif
                </div>

                @if ($this->editingRoleId === $role->id && $this->editingRole)
                    <form
                        wire:submit="saveRole"
                        class="flex flex-col gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-white/5"
                        data-test="edit-role-form-{{ $role->id }}"
                    >
                        <flux:checkbox.group wire:model="editPermissionIds" class="columns-1 gap-x-8 sm:columns-2 lg:columns-3">
                            @foreach ($this->editPermissionGroups as $group => $permissions)
                                <div class="mb-3 flex break-inside-avoid flex-col gap-1" wire:key="edit-perm-group-{{ \Illuminate\Support\Str::slug($group) }}">
                                    <flux:text size="xs" class="font-medium text-zinc-400">{{ $group }}</flux:text>
                                    <div class="flex flex-col gap-1">
                                        @foreach ($permissions as $permission)
                                            <flux:checkbox value="{{ $permission->id }}" :label="$this->permissionLabel($permission->name)" :description="$this->permissionDescription($permission->name)" data-test="edit-permission-{{ $permission->name }}" />
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </flux:checkbox.group>

                        <div class="flex items-center gap-2">
                            <flux:button type="submit" size="sm" variant="primary" data-test="save-edit-role">{{ __('Save') }}</flux:button>
                            <flux:button type="button" size="sm" variant="ghost" wire:click="cancelEdit" data-test="cancel-edit-role">{{ __('Cancel') }}</flux:button>
                        </div>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

    <form wire:submit="createRole" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10" data-test="create-role-form">
        <flux:heading size="sm">{{ __('Add a custom role') }}</flux:heading>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" data-test="role-name" />

            <flux:select wire:model.live="parentId" :label="__('Parent role')" data-test="role-parent">
                @foreach ($this->assignableParents as $parent)
                    <flux:select.option :value="$parent->id">{{ $parent->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:error name="parentId" />
        <flux:error name="name" />

        <flux:field>
            <flux:label>{{ __('Permissions') }}</flux:label>
            <flux:description>{{ __('A new role can hold any subset of its parent role\'s permissions.') }}</flux:description>

            <flux:checkbox.group wire:model="permissionIds" class="mt-2 columns-1 gap-x-8 sm:columns-2 lg:columns-3">
                @forelse ($this->permissionGroups as $group => $permissions)
                    <div class="mb-3 flex break-inside-avoid flex-col gap-1" wire:key="perm-group-{{ \Illuminate\Support\Str::slug($group) }}">
                        <flux:text size="xs" class="font-medium text-zinc-400">{{ $group }}</flux:text>
                        <div class="flex flex-col gap-1">
                            @foreach ($permissions as $permission)
                                <flux:checkbox value="{{ $permission->id }}" :label="$this->permissionLabel($permission->name)" :description="$this->permissionDescription($permission->name)" data-test="role-permission-{{ $permission->name }}" />
                            @endforeach
                        </div>
                    </div>
                @empty
                    <flux:text size="sm" class="text-zinc-400">{{ __('Choose a parent role to see the permissions you can delegate.') }}</flux:text>
                @endforelse
            </flux:checkbox.group>
        </flux:field>

        <flux:button type="submit" variant="primary" data-test="save-role">{{ __('Add role') }}</flux:button>

        <div class="flex flex-col gap-2 border-t border-zinc-200 pt-3 dark:border-white/10">
            <flux:text size="xs" class="font-medium text-zinc-400">{{ __('Quick templates') }}</flux:text>
            <flux:description>{{ __('Create a preset role under the chosen parent, bounded by its permissions.') }}</flux:description>
            <div class="flex flex-wrap gap-2" data-test="role-templates">
                @foreach ($this->templates as $template)
                    <flux:button
                        type="button"
                        size="sm"
                        variant="filled"
                        wire:click="applyTemplate('{{ $template }}')"
                        data-test="role-template-{{ \Illuminate\Support\Str::slug($template) }}"
                    >{{ __($template) }}</flux:button>
                @endforeach
            </div>
        </div>
    </form>
</div>
