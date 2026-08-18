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

        {{-- Detail: the selected role, read-only --}}
        <div class="md:col-span-2">
            @if ($this->selectedRole)
                <div
                    class="flex flex-col gap-5 rounded-lg border border-zinc-200 p-4 dark:border-white/10"
                    wire:key="role-detail-{{ $this->selectedRole->id }}"
                    data-test="role-detail"
                >
                    <div class="flex flex-col gap-1">
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
                </div>
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
