{{-- Top-right account entry point. The avatar is the account menu again: the
     notifications bell sits to its left and owns the unread badge, so the two
     jobs no longer share one control. Reuses the shared account items, like the
     bottom-left sidebar menu. --}}
<flux:dropdown position="bottom" align="end">
    <button
        type="button"
        class="flex cursor-pointer items-center"
        aria-label="{{ __('Account') }}"
        data-test="header-account-menu"
    >
        <flux:avatar
            size="sm"
            :name="auth()->user()->name"
            :src="auth()->user()->avatarUrl()"
            :initials="auth()->user()->initials()"
        />
    </button>

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

        <x-account-menu-items test-prefix="header-account" />
    </flux:menu>
</flux:dropdown>
