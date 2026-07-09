<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('User administration') }}</flux:heading>

        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search name or email')"
            class="max-w-xs"
            data-test="user-search"
        />
    </div>

    {{-- User accounts --}}
    <div class="flex flex-col gap-3">
        @foreach ($this->users as $user)
            <flux:card class="flex flex-col gap-4" wire:key="user-{{ $user->id }}" data-test="user-row-{{ $user->id }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <x-user-avatar :user="$user" size="sm" />
                        <div class="flex flex-col">
                            <span class="font-medium text-zinc-800 dark:text-zinc-100">
                                {{ $user->name }}
                                @if ($user->is(auth()->user()))
                                    <flux:badge size="sm" color="zinc">{{ __('You') }}</flux:badge>
                                @endif
                            </span>
                            <flux:text size="sm" class="text-zinc-500">{{ $user->email }}</flux:text>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        @if ($user->pendingInvitations->isNotEmpty())
                            <flux:tooltip :content="__('Pending invitations sent by this user')">
                                <flux:badge size="sm" color="blue" data-test="pending-invites-{{ $user->id }}">
                                    {{ __(':count pending', ['count' => $user->pendingInvitations->count()]) }}
                                </flux:badge>
                            </flux:tooltip>
                        @endif

                        @if ($user->isDeactivated())
                            <flux:badge color="amber" data-test="status-{{ $user->id }}">{{ __('Deactivated') }}</flux:badge>
                        @else
                            <flux:badge color="green" data-test="status-{{ $user->id }}">{{ __('Active') }}</flux:badge>
                        @endif

                        <flux:button size="sm" variant="ghost" icon="folder" wire:click="manageProjects({{ $user->id }})" data-test="manage-projects-{{ $user->id }}">
                            {{ __('Projects') }}
                        </flux:button>

                        @unless ($user->is(auth()->user()))
                            @if ($user->isDeactivated())
                                <flux:button size="sm" variant="ghost" icon="lock-open" wire:click="reactivate({{ $user->id }})" data-test="reactivate-{{ $user->id }}">
                                    {{ __('Reactivate') }}
                                </flux:button>
                            @else
                                <flux:button size="sm" variant="ghost" icon="lock-closed" wire:click="deactivate({{ $user->id }})" data-test="deactivate-{{ $user->id }}">
                                    {{ __('Deactivate') }}
                                </flux:button>
                            @endif

                            <flux:tooltip :content="__('Remove account')">
                                <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmRemoval({{ $user->id }})" data-test="remove-{{ $user->id }}" />
                            </flux:tooltip>
                        @endunless
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Permissions') }}:</flux:text>
                    @foreach ($this->permissions as $permission)
                        @php($granted = $user->hasPermission($permission))
                        @if ($this->locksSelfOutOfManagement($user, $permission))
                            <flux:tooltip :content="__('You cannot revoke your own user management permission.')">
                                <flux:button
                                    size="xs"
                                    variant="primary"
                                    icon="check"
                                    disabled
                                    :data-test="'perm-'.$user->id.'-'.$permission->value"
                                >
                                    {{ $permission->label() }}
                                </flux:button>
                            </flux:tooltip>
                        @else
                            <flux:button
                                size="xs"
                                :variant="$granted ? 'primary' : 'ghost'"
                                :icon="$granted ? 'check' : 'plus'"
                                wire:click="togglePermission({{ $user->id }}, '{{ $permission->value }}')"
                                :data-test="'perm-'.$user->id.'-'.$permission->value"
                            >
                                {{ $permission->label() }}
                            </flux:button>
                        @endif
                    @endforeach
                </div>
            </flux:card>
        @endforeach
    </div>

    {{-- Pending invitations --}}
    <div class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Pending invitations') }}</flux:heading>

        @if ($this->pendingInvitations->isEmpty())
            <flux:card class="text-center">
                <flux:text>{{ __('No pending invitations.') }}</flux:text>
            </flux:card>
        @else
            @foreach ($this->pendingInvitations as $invitation)
                <flux:card class="flex flex-wrap items-center justify-between gap-3" wire:key="invitation-{{ $invitation->id }}" data-test="invitation-row-{{ $invitation->id }}">
                    <div class="flex flex-col">
                        <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $invitation->email }}</span>
                        <flux:text size="sm" class="text-zinc-500">
                            {{ __('Invited by :name · expires :when', ['name' => $invitation->inviter?->name ?? __('Deleted user'), 'when' => $invitation->expires_at->diffForHumans()]) }}
                        </flux:text>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" icon="paper-airplane" wire:click="resendInvitation({{ $invitation->id }})" data-test="resend-invitation-{{ $invitation->id }}">
                            {{ __('Resend') }}
                        </flux:button>
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="revokeInvitation({{ $invitation->id }})" data-test="revoke-invitation-{{ $invitation->id }}">
                            {{ __('Revoke') }}
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        @endif
    </div>

    {{-- Removal confirmation --}}
    <flux:modal wire:model.self="confirmingRemoval" wire:close="cancelRemoval" class="md:w-96">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Remove account') }}</flux:heading>

            <flux:text>
                {{ __('Remove :name? They will be signed out and lose access. Their project access, task assignments and notifications are dropped; comments they wrote are kept as the work of a removed user. This cannot be undone.', ['name' => $this->removalTarget?->name]) }}
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="cancelRemoval">{{ __('Cancel') }}</flux:button>
                <flux:button type="button" variant="danger" wire:click="removeUser" data-test="confirm-remove">{{ __('Remove account') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Manage a user's project memberships --}}
    <flux:modal wire:model.self="managingProjects" class="md:w-xl" data-test="manage-projects-modal">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">
                {{ $this->managedUser ? __('Projects for :name', ['name' => $this->managedUser->name]) : __('Projects') }}
            </flux:heading>

            <div class="flex max-h-96 flex-col gap-2 overflow-y-auto" data-test="manage-projects-list">
                @foreach ($this->manageableProjects as $project)
                    @php($row = $this->projectRow($project))
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-white/5" wire:key="mp-{{ $project->id }}" data-test="manage-project-row-{{ $project->id }}">
                        <flux:text class="min-w-0 flex-1 truncate">{{ $project->title }} <span class="text-zinc-400">{{ $project->short_name }}</span></flux:text>

                        @if ($row['heldNames']->isEmpty())
                            <flux:button type="button" size="xs" variant="ghost" icon="plus" wire:click="addUserToProject({{ $project->id }})" data-test="mp-add-{{ $project->id }}">{{ __('Add') }}</flux:button>
                        @else
                            <div class="flex flex-wrap items-center justify-end gap-1.5" data-test="mp-roles-{{ $project->id }}">
                                @foreach ($row['heldNames'] as $name)
                                    <x-role-badge :name="$name" data-test="mp-role-{{ $project->id }}-{{ $name }}">
                                        @unless ($row['readonly'])
                                            <flux:badge.close
                                                wire:click="removeUserProjectRole({{ $project->id }}, '{{ $name }}')"
                                                :aria-label="__('Remove role')"
                                                data-test="mp-remove-role-{{ $project->id }}-{{ $name }}"
                                            />
                                        @endunless
                                    </x-role-badge>
                                @endforeach

                                @unless ($row['readonly'])
                                    @if ($row['addable']->isNotEmpty())
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button
                                                type="button"
                                                size="xs"
                                                variant="subtle"
                                                icon="plus"
                                                data-test="mp-add-role-{{ $project->id }}"
                                            >{{ __('Add role') }}</flux:button>

                                            <flux:menu>
                                                @foreach ($row['addable'] as $role)
                                                    <flux:menu.item
                                                        wire:click="addUserProjectRole({{ $project->id }}, '{{ $role }}')"
                                                        data-test="mp-add-role-{{ $project->id }}-{{ $role }}"
                                                    >{{ \Illuminate\Support\Str::headline($role) }}</flux:menu.item>
                                                @endforeach
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif

                                    <flux:button type="button" size="xs" variant="ghost" icon="x-mark" :aria-label="__('Remove member')" wire:click="removeUserFromProject({{ $project->id }})" data-test="mp-remove-{{ $project->id }}" />
                                @endunless
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </flux:modal>
</div>
