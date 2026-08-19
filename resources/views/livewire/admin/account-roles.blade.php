<div class="app-content mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="account-roles">
    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <div class="flex min-w-0 items-center gap-3">
            <flux:button
                size="sm"
                variant="ghost"
                icon="arrow-left"
                :href="route('admin.users')"
                :aria-label="__('Back to user administration')"
                title="{{ __('Back to user administration') }}"
                wire:navigate
                data-test="back-to-users"
            />
            <flux:heading size="xl" class="min-w-0 truncate">{{ __('Account roles') }}</flux:heading>
        </div>

        <flux:button size="sm" variant="primary" icon="plus" wire:click="startCreate" data-test="new-account-role">
            {{ __('New role') }}
        </flux:button>
    </div>

    <flux:text class="text-zinc-500">
        {{ __('Bundle account permissions under a name, then assign the role to people in user administration. Permission chips there stay available for one-off grants.') }}
    </flux:text>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        {{-- Master: the named roles --}}
        <div class="flex flex-col gap-4 self-start">
            @if ($this->roleTree === [])
                <x-empty-state :heading="__('No account roles yet')" test="account-roles-empty" icon="shield-check">
                    <flux:text size="sm" class="text-zinc-400">
                        {{ __('Create one to hand out several account permissions at once.') }}
                    </flux:text>
                </x-empty-state>
            @else
                <div
                    class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10"
                    data-test="account-roles-list"
                >
                    @foreach ($this->roleTree as $node)
                        @php($role = $node['role'])
                        <button
                            type="button"
                            wire:key="account-role-{{ $role->id }}"
                            wire:click="selectRole({{ $role->id }})"
                            @class([
                                'flex items-center justify-between gap-2 p-3 text-start',
                                'bg-zinc-100 dark:bg-white/10' => $this->selectedRole?->id === $role->id,
                            ])
                            style="padding-inline-start: {{ $node['depth'] * 1.25 + 0.75 }}rem"
                            data-test="account-role-row-{{ $role->id }}"
                        >
                            <flux:heading size="sm" class="truncate">{{ $role->name }}</flux:heading>

                            <flux:badge size="sm" color="zinc" data-test="account-role-members-{{ $role->id }}">
                                {{ $this->memberCounts[$role->id] ?? 0 }}</flux:badge>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Detail --}}
        <div class="flex flex-col gap-4 md:col-span-2">
            @if ($this->selectedRole)
                <div
                    class="flex flex-col gap-5 rounded-lg border border-zinc-200 p-4 dark:border-white/10"
                    wire:key="account-role-detail-{{ $this->selectedRole->id }}"
                    data-test="account-role-detail"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-col gap-1">
                            <flux:heading size="lg" data-test="account-role-detail-name">
                                {{ $this->selectedRole->name }}</flux:heading>

                            @if ($this->selectedRole->description)
                                <flux:text class="text-zinc-500">{{ $this->selectedRole->description }}</flux:text>
                            @endif

                            @if ($this->selectedRoleParent)
                                <flux:text size="sm" class="text-zinc-400" data-test="account-role-detail-parent">
                                    {{ __('Delegated from :role', ['role' => $this->selectedRoleParent->name]) }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="plus"
                                wire:click="startCreate({{ $this->selectedRole->id }})"
                                data-test="add-child-account-role"
                            >{{ __('Add child role') }}</flux:button>
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Edit role')"
                                title="{{ __('Edit role') }}"
                                wire:click="startEdit"
                                data-test="edit-account-role"
                            />
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                :aria-label="__('Delete role')"
                                title="{{ __('Delete role') }}"
                                wire:click="deleteRole"
                                wire:confirm="{{ $this->deleteConsequence }}"
                                data-test="delete-account-role"
                            />
                        </div>
                    </div>

                    @if ($this->editing)
                        <form wire:submit="saveRole" class="flex flex-col gap-3" data-test="edit-account-role-form">
                            <flux:input wire:model="editName" :label="__('Name')" data-test="edit-account-role-name" />
                            <flux:input
                                wire:model="editDescription"
                                :label="__('Description')"
                                data-test="edit-account-role-description"
                            />

                            <flux:field>
                                <flux:label>{{ __('Permissions') }}</flux:label>
                                <flux:description>{{ __('A role can only hand out permissions you hold yourself.') }}</flux:description>

                                <x-permission-picker
                                    :groups="$this->catalogGroups"
                                    :allowed="$this->editAllowedPermissions"
                                    model="editPermissionIds"
                                    test-prefix="edit-account-permission"
                                    :resolver="$this"
                                    :empty-message="__('You hold no account permissions yourself, so there is nothing to put into a role.')"
                                    class="mt-2"
                                />
                            </flux:field>

                            @if ($this->beyondReachPermissions !== [])
                                <flux:text size="sm" class="text-zinc-500" data-test="account-role-beyond-reach">
                                    {{ __('This role also grants :permissions. You do not hold them, so they stay untouched.', ['permissions' => implode(', ', $this->beyondReachPermissions)]) }}
                                </flux:text>
                            @endif

                            <div class="flex items-center gap-2">
                                <flux:button
                                    type="submit"
                                    size="sm"
                                    variant="primary"
                                    data-test="save-account-role"
                                >{{ __('Save') }}</flux:button>
                                <flux:button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    wire:click="cancelEdit"
                                    data-test="cancel-edit-account-role"
                                >{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-col gap-2">
                            <flux:heading size="sm">{{ __('Permissions') }}</flux:heading>

                            @if ($this->selectedRolePermissions === [])
                                <flux:text size="sm" class="text-zinc-500" data-test="account-role-no-permissions">
                                    {{ __('No permissions') }}
                                </flux:text>
                            @else
                                <div class="flex flex-wrap gap-1.5" data-test="account-role-permissions">
                                    @foreach ($this->selectedRolePermissions as $label)
                                        <flux:badge size="sm" color="zinc">{{ $label }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-col gap-2">
                            <flux:heading size="sm">{{ __('Members') }}</flux:heading>

                            @if ($this->selectedRoleMembers->isEmpty())
                                <flux:text size="sm" class="text-zinc-500" data-test="account-role-no-members">
                                    {{ __('Nobody holds this role.') }}
                                </flux:text>
                            @else
                                <div class="flex flex-wrap gap-3" data-test="account-role-members">
                                    @foreach ($this->selectedRoleMembers as $member)
                                        <x-user-link
                                            :user="$member"
                                            class="flex items-center gap-2"
                                            wire:key="account-role-member-{{ $member->id }}"
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
            @endif

            @if ($this->creating)
                <form
                    wire:submit="createRole"
                    class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10"
                    data-test="create-account-role-form"
                >
                    <flux:heading size="sm">
                        @if ($this->creatingParent)
                            {{ __('New role under :role', ['role' => $this->creatingParent->name]) }}
                        @else
                            {{ __('New account role') }}
                        @endif
                    </flux:heading>

                    <flux:input wire:model="newName" :label="__('Name')" data-test="new-account-role-name" />

                    <flux:field>
                        <flux:label>{{ __('Permissions') }}</flux:label>
                        <flux:description>{{ __('A role can only hand out permissions you hold yourself.') }}</flux:description>

                        <x-permission-picker
                            :groups="$this->catalogGroups"
                            :allowed="$this->createAllowedPermissions"
                            model="newPermissionIds"
                            test-prefix="new-account-permission"
                            :resolver="$this"
                            :empty-message="__('You hold no account permissions yourself, so there is nothing to put into a role.')"
                            class="mt-2"
                        />
                    </flux:field>

                    <div class="flex items-center gap-2">
                        <flux:button
                            type="submit"
                            size="sm"
                            variant="primary"
                            data-test="save-new-account-role"
                        >{{ __('Add role') }}</flux:button>
                        <flux:button
                            type="button"
                            size="sm"
                            variant="ghost"
                            wire:click="cancelCreate"
                            data-test="cancel-new-account-role"
                        >{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
