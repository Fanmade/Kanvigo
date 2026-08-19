<div class="app-content mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="project-roles">
    {{-- Header --}}
    <x-project-settings-header :project="$this->project" :title="__('Roles')" />

    <flux:text class="text-zinc-500">
        {{ __('The roles you may manage in this project: the ones you hold and everything delegated beneath them.') }}
    </flux:text>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        {{-- Master: the visible role tree, names and hierarchy only --}}
        <div
            class="flex flex-col divide-y divide-zinc-200 self-start rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10"
            data-test="roles-list"
        >
            @foreach ($this->roleTree as $node)
                @php($role = $node['role'])
                <button
                    type="button"
                    wire:key="role-{{ $role->id }}"
                    wire:click="selectRole({{ $role->id }})"
                    @class([
                        'flex items-center justify-between gap-2 p-3 text-start',
                        'bg-zinc-100 dark:bg-white/10' => $this->selectedRole?->id === $role->id,
                    ])
                    style="padding-inline-start: {{ $node['depth'] * 1.25 + 0.75 }}rem"
                    data-test="role-row-{{ $role->id }}"
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <flux:heading size="sm" class="truncate">{{ $role->name }}</flux:heading>

                        @if ($this->isBaseRole($role))
                            <flux:badge size="sm" color="zinc" data-test="role-base-{{ $role->id }}">
                                {{ __('Base') }}</flux:badge>
                        @endif
                    </span>

                    <flux:badge size="sm" color="zinc" data-test="role-members-{{ $role->id }}">
                        {{ $this->memberCounts[$role->id] ?? 0 }}</flux:badge>
                </button>
            @endforeach
        </div>

        {{-- Detail: the selected role --}}
        <div class="flex flex-col gap-4 md:col-span-2">
            @if ($this->selectedRole)
                <div
                    class="flex flex-col gap-5 rounded-lg border border-zinc-200 p-4 dark:border-white/10"
                    wire:key="role-detail-{{ $this->selectedRole->id }}"
                    data-test="role-detail"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-col gap-1">
                            <flux:heading size="lg" data-test="role-detail-name">{{ $this->selectedRole->name }}</flux:heading>

                            @if ($this->selectedRole->description)
                                <flux:text class="text-zinc-500">{{ $this->selectedRole->description }}</flux:text>
                            @endif

                            @if ($this->selectedRoleParent)
                                <flux:text size="sm" class="text-zinc-400" data-test="role-detail-parent">
                                    {{ __('Delegated from :role', ['role' => $this->selectedRoleParent->name]) }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="plus"
                                wire:click="startCreate"
                                data-test="add-child-role"
                            >{{ __('Add child role') }}</flux:button>

                            @if ($this->canEditSelected)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil-square"
                                    :aria-label="__('Edit role')"
                                    title="{{ __('Edit role') }}"
                                    wire:click="startEdit"
                                    data-test="edit-role"
                                />
                            @endif

                            @if ($this->canResetSelected)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-path"
                                    :aria-label="__('Reset to defaults')"
                                    title="{{ __('Reset to defaults') }}"
                                    wire:click="resetToDefaults"
                                    wire:confirm="{{ $this->resetConsequence }}"
                                    data-test="reset-role"
                                />
                            @endif

                            @if ($this->canRemoveSelected)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrows-right-left"
                                    :aria-label="__('Move under…')"
                                    title="{{ __('Move under…') }}"
                                    wire:click="startMove"
                                    data-test="move-role"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    :aria-label="__('Delete role')"
                                    title="{{ __('Delete role') }}"
                                    wire:click="deleteRole"
                                    wire:confirm="{{ $this->deleteConsequence }}"
                                    data-test="delete-role"
                                />
                            @endif
                        </div>
                    </div>

                    @if ($this->readOnlyReason)
                        <flux:text size="sm" class="text-zinc-500" data-test="role-read-only-reason">
                            {{ $this->readOnlyReason }}
                        </flux:text>
                    @endif

                    @if ($this->moving)
                        <div class="flex flex-col gap-2" data-test="move-role-panel">
                            <flux:heading size="sm">
                                {{ __('Move :role under…', ['role' => $this->selectedRole->name]) }}
                            </flux:heading>

                            <flux:error name="moving" />

                            @if ($this->moveTargets === [])
                                <flux:text size="sm" class="text-zinc-500" data-test="no-move-targets">
                                    {{ __('There is no other role you manage to move this one under.') }}
                                </flux:text>
                            @else
                                <div
                                    class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10"
                                >
                                    @foreach ($this->moveTargets as $target)
                                        <div
                                            class="flex items-center justify-between gap-3 p-3"
                                            wire:key="move-target-{{ $target['role']->id }}"
                                        >
                                            <div class="flex min-w-0 flex-col">
                                                <flux:text class="truncate">{{ $target['role']->name }}</flux:text>

                                                @if ($target['exceeding'] !== [])
                                                    <flux:text
                                                        size="sm"
                                                        class="text-zinc-500"
                                                        data-test="move-blocked-{{ $target['role']->id }}"
                                                    >
                                                        {{ __('Revoke :permissions first — this role does not hold them.', ['permissions' => collect($target['exceeding'])->map(fn (string $name) => $this->permissionLabel($name))->implode(', ')]) }}
                                                    </flux:text>
                                                @endif
                                            </div>

                                            <flux:button
                                                size="xs"
                                                variant="subtle"
                                                wire:click="moveRole({{ $target['role']->id }})"
                                                :disabled="$target['exceeding'] !== []"
                                                data-test="move-to-{{ $target['role']->id }}"
                                            >{{ __('Move here') }}</flux:button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="cancelMove"
                                    data-test="cancel-move-role"
                                >{{ __('Cancel') }}</flux:button>
                            </div>
                        </div>
                    @endif

                    @if ($this->editing)
                        <form wire:submit="saveRole" class="flex flex-col gap-3" data-test="edit-role-form">
                            @if ($this->canRemoveSelected)
                                <flux:input wire:model="editName" :label="__('Name')" data-test="edit-role-name" />
                            @else
                                <flux:input
                                    :value="$this->selectedRole->name"
                                    :label="__('Name')"
                                    :description="__('Base role names are referenced from code and stay fixed.')"
                                    disabled
                                    data-test="edit-role-name"
                                />
                            @endif

                            <flux:input
                                wire:model="editDescription"
                                :label="__('Description')"
                                data-test="edit-role-description"
                            />

                            <flux:field>
                                <flux:label>{{ __('Permissions') }}</flux:label>
                                <flux:description>{{ __('A role can hold any subset of its parent role\'s permissions. Removing one also removes it from the roles beneath this one.') }}</flux:description>

                                <x-permission-picker
                                    :groups="$this->catalogGroups"
                                    :allowed="$this->editAllowedPermissions"
                                    model="editPermissionIds"
                                    test-prefix="edit-permission"
                                    :resolver="$this"
                                    class="mt-2"
                                />
                            </flux:field>

                            <div class="flex items-center gap-2">
                                <flux:button
                                    type="submit"
                                    size="sm"
                                    variant="primary"
                                    data-test="save-role"
                                >{{ __('Save') }}</flux:button>
                                <flux:button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    wire:click="cancelEdit"
                                    data-test="cancel-edit-role"
                                >{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-col gap-2">
                            <flux:heading size="sm">{{ __('Permissions') }}</flux:heading>

                            @if (empty($this->selectedRolePermissionGroups))
                                <flux:text size="sm" class="text-zinc-500" data-test="role-detail-no-permissions">
                                    {{ __('No permissions') }}
                                </flux:text>
                            @else
                                <dl class="flex flex-col gap-2" data-test="role-detail-permissions">
                                    @foreach ($this->selectedRolePermissionGroups as $group => $labels)
                                        <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-3">
                                            <dt class="w-40 shrink-0">
                                                <flux:text size="sm" class="text-zinc-500">{{ __($group) }}</flux:text>
                                            </dt>
                                            <dd class="min-w-0">
                                                <flux:text size="sm">{{ implode(', ', $labels) }}</flux:text>
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>

                        <div class="flex flex-col gap-2">
                            <flux:heading size="sm">{{ __('Members') }}</flux:heading>

                            @if ($this->selectedRoleMembers->isEmpty())
                                <flux:text size="sm" class="text-zinc-500" data-test="role-detail-no-members">
                                    {{ __('Nobody holds this role.') }}
                                </flux:text>
                            @else
                                <div class="flex flex-wrap gap-3" data-test="role-detail-members">
                                    @foreach ($this->selectedRoleMembers as $member)
                                        <x-user-link
                                            :user="$member"
                                            class="flex items-center gap-2"
                                            wire:key="role-member-{{ $member->id }}"
                                        >
                                            <x-user-avatar :user="$member" />
                                            <flux:text size="sm">{{ $member->name }}</flux:text>
                                        </x-user-link>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($this->creating)
                    <form
                        wire:submit="createRole"
                        class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10"
                        data-test="create-role-form"
                    >
                        <flux:heading size="sm">
                            {{ __('New role under :role', ['role' => $this->selectedRole->name]) }}
                        </flux:heading>

                        <flux:input wire:model="newName" :label="__('Name')" data-test="new-role-name" />

                        <flux:field>
                            <flux:label>{{ __('Permissions') }}</flux:label>
                            <flux:description>{{ __('A new role can hold any subset of its parent role\'s permissions.') }}</flux:description>

                            <div class="mt-2" data-test="copy-role-permissions">
                                <flux:dropdown align="start">
                                    <flux:button
                                        type="button"
                                        size="xs"
                                        variant="subtle"
                                        icon:trailing="chevron-down"
                                        data-test="copy-role-trigger"
                                    >{{ __('Copy from role') }}</flux:button>
                                    <flux:menu>
                                        @foreach ($this->roleTree as $node)
                                            <flux:menu.item
                                                wire:click="copyPermissionsFrom({{ $node['role']->id }})"
                                                data-test="use-role-permissions-{{ $node['role']->id }}"
                                            >
                                                {{ str_repeat('— ', $node['depth']).$node['role']->name }}</flux:menu.item>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            </div>

                            <x-permission-picker
                                :groups="$this->catalogGroups"
                                :allowed="$this->createAllowedPermissions"
                                model="newPermissionIds"
                                test-prefix="new-permission"
                                :resolver="$this"
                                class="mt-2"
                            />
                        </flux:field>

                        <div class="flex items-center gap-2">
                            <flux:button
                                type="submit"
                                size="sm"
                                variant="primary"
                                data-test="save-new-role"
                            >{{ __('Add role') }}</flux:button>
                            <flux:button
                                type="button"
                                size="sm"
                                variant="ghost"
                                wire:click="cancelCreate"
                                data-test="cancel-new-role"
                            >{{ __('Cancel') }}</flux:button>
                        </div>
                    </form>
                @endif
            @else
                <x-empty-state :heading="__('No roles to manage')" test="roles-empty">
                    <flux:text size="sm" class="text-zinc-400">
                        {{ __('You hold no role in this project that can be delegated from.') }}
                    </flux:text>
                </x-empty-state>
            @endif
        </div>
    </div>
</div>
