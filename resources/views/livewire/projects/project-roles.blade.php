<div class="flex flex-col gap-4" data-test="project-roles">
    <div x-data="{ open: true }">
        <button
            type="button"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            class="flex w-full items-center gap-2 text-start"
            data-test="toggle-roles-list"
        >
            <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-90'" />
            <flux:heading size="sm">{{ __('Existing roles') }}</flux:heading>
            <flux:badge size="sm" color="zinc">{{ count($this->roleTree) }}</flux:badge>
        </button>

        <div x-show="open" x-cloak class="mt-3 flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10" data-test="roles-list">
        @foreach ($this->roleTree as $node)
            @php($role = $node['role'])
            <div wire:key="role-{{ $role->id }}" data-test="role-row-{{ $role->id }}" x-data="{ open: false }">
                <div class="flex items-center justify-between gap-3 p-3" style="padding-inline-start: {{ $node['depth'] * 1.25 + 0.75 }}rem">
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        x-bind:aria-expanded="open ? 'true' : 'false'"
                        class="flex min-w-0 items-center gap-1.5 text-start"
                        data-test="toggle-role-{{ $role->id }}"
                    >
                        <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-90'" />
                        <flux:heading size="sm" class="truncate">{{ $role->name }}</flux:heading>
                    </button>

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

                <div x-show="open" x-cloak class="pb-3 pe-3" style="padding-inline-start: {{ $node['depth'] * 1.25 + 2 }}rem">
                    <flux:text size="sm" class="text-zinc-500" data-test="role-permissions-{{ $role->id }}">
                        {{ collect($this->permissionsByRole[$role->id] ?? [])->map(fn (string $name) => $this->permissionLabel($name))->implode(', ') ?: __('No permissions') }}
                    </flux:text>
                </div>

                @if ($this->editingRoleId === $role->id && $this->editingRole)
                    <div class="px-3 pb-3">
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
                                                <div class="flex items-center gap-1.5">
                                                    <flux:checkbox value="{{ $permission->id }}" :label="$this->permissionPickerLabel($permission->name)" data-test="edit-permission-{{ $permission->name }}" />
                                                    @if ($description = $this->permissionDescription($permission->name))
                                                        <flux:tooltip :content="$description">
                                                            <flux:icon.question-mark-circle variant="micro" class="cursor-help text-zinc-400" tabindex="0" data-test="edit-permission-hint-{{ $permission->name }}" />
                                                        </flux:tooltip>
                                                    @endif
                                                </div>
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
                    </div>
                @endif
            </div>
        @endforeach
        </div>
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

            @if (! empty($this->permissionGroups))
                <div class="mt-2" data-test="copy-role-permissions">
                    <flux:dropdown align="start">
                        <flux:button type="button" size="xs" variant="subtle" icon:trailing="chevron-down" data-test="copy-role-trigger">{{ __('Copy from role') }}</flux:button>
                        <flux:menu>
                            @foreach ($this->roleTree as $node)
                                <flux:menu.item
                                    wire:click="selectRolePermissions({{ $node['role']->id }})"
                                    data-test="use-role-permissions-{{ $node['role']->id }}"
                                >{{ str_repeat('— ', $node['depth']).$node['role']->name }}</flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @endif

            <flux:checkbox.group wire:model="permissionIds" class="mt-2 columns-1 gap-x-8 sm:columns-2 lg:columns-3">
                @forelse ($this->permissionGroups as $group => $permissions)
                    <div class="mb-3 flex break-inside-avoid flex-col gap-1" wire:key="perm-group-{{ \Illuminate\Support\Str::slug($group) }}">
                        <flux:text size="xs" class="font-medium text-zinc-400">{{ $group }}</flux:text>
                        <div class="flex flex-col gap-1">
                            @foreach ($permissions as $permission)
                                <div class="flex items-center gap-1.5">
                                    <flux:checkbox value="{{ $permission->id }}" :label="$this->permissionPickerLabel($permission->name)" data-test="role-permission-{{ $permission->name }}" />
                                    @if ($description = $this->permissionDescription($permission->name))
                                        <flux:tooltip :content="$description">
                                            <flux:icon.question-mark-circle variant="micro" class="cursor-help text-zinc-400" tabindex="0" data-test="role-permission-hint-{{ $permission->name }}" />
                                        </flux:tooltip>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <flux:text size="sm" class="text-zinc-400">{{ __('Choose a parent role to see the permissions you can delegate.') }}</flux:text>
                @endforelse
            </flux:checkbox.group>
        </flux:field>

        <flux:button type="submit" variant="primary" data-test="save-role">{{ __('Add role') }}</flux:button>
    </form>
</div>
