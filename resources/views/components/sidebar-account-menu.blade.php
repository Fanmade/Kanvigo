{{-- Bottom-left account/settings entry point for the sidebar shell. Opens upward
     (the trigger sits in the sidebar footer) and reuses the shared account items. --}}
<flux:dropdown position="top" align="start">
    <flux:sidebar.profile
        :name="auth()->user()->name"
        :avatar="auth()->user()->avatarUrl()"
        :initials="auth()->user()->initials()"
        icon:trailing="chevrons-up-down"
        data-test="sidebar-account-menu"
    />

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth()->user()->name"
                :src="auth()->user()->avatarUrl()"
                :initials="auth()->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>

        <flux:menu.separator />

        <x-account-menu-items test-prefix="sidebar-account" />
    </flux:menu>
</flux:dropdown>
