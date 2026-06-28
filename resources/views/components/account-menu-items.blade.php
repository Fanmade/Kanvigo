@props(['testPrefix' => null])

{{-- Shared account actions. Rendered inside the top-right notifications menu and
     the bottom-left sidebar account menu so the two can't drift. --}}
<flux:menu.item
    :href="route('users.show', auth()->user())"
    icon="user"
    wire:navigate
    :data-test="$testPrefix ? $testPrefix.'-profile' : null"
>
    {{ __('View profile') }}
</flux:menu.item>

<flux:menu.item
    :href="route('profile.edit')"
    icon="cog"
    wire:navigate
    :data-test="$testPrefix ? $testPrefix.'-settings' : null"
>
    {{ __('Settings') }}
</flux:menu.item>

<form method="POST" action="{{ route('logout') }}" class="w-full">
    @csrf
    <flux:menu.item
        as="button"
        type="submit"
        icon="arrow-right-start-on-rectangle"
        class="w-full cursor-pointer"
        :data-test="$testPrefix ? $testPrefix.'-logout' : null"
    >
        {{ __('Log out') }}
    </flux:menu.item>
</form>
