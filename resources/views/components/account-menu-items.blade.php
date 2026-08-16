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

{{-- Theme, two clicks from anywhere. It drives Flux's own `$flux.appearance`
     store — the same state the Appearance settings page binds to — so the two
     surfaces always agree and the choice persists. Purely client-side: the
     theme applies at once, without a roundtrip.

     A segmented icon control rather than three menu rows or a submenu: it keeps
     the menu short, shows the active theme at a glance, and (unlike a submenu,
     whose trigger drops custom attributes) stays addressable per rendering. --}}
<flux:menu.separator />

<div class="px-2 py-1.5">
    <flux:radio.group x-data variant="segmented" size="sm" class="w-full *:flex-1" x-model="$flux.appearance">
        <flux:radio
            value="light"
            icon="sun"
            :aria-label="__('Light')"
            :data-test="$testPrefix ? $testPrefix.'-theme-light' : null"
        />
        <flux:radio
            value="dark"
            icon="moon"
            :aria-label="__('Dark')"
            :data-test="$testPrefix ? $testPrefix.'-theme-dark' : null"
        />
        <flux:radio
            value="system"
            icon="computer-desktop"
            :aria-label="__('System')"
            :data-test="$testPrefix ? $testPrefix.'-theme-system' : null"
        />
    </flux:radio.group>
</div>

{{-- Language, unlike the theme, is a server-side setting: each option posts the
     choice and the page comes back rendered in it. The options are labelled in
     their own language so they read the same whichever locale is active. --}}
<flux:menu.separator />

<flux:menu.group :heading="__('Language')">
    @foreach (\App\Http\Middleware\SetLocale::SUPPORTED as $code => $name)
        <form method="POST" action="{{ route('locale.update') }}" class="w-full">
            @csrf
            <input type="hidden" name="locale" value="{{ $code }}">
            <flux:menu.item
                as="button"
                type="submit"
                :icon:trailing="app()->getLocale() === $code ? 'check' : null"
                class="w-full cursor-pointer"
                :data-test="$testPrefix ? $testPrefix.'-language-'.$code : null"
            >
                {{ $name }}
            </flux:menu.item>
        </form>
    @endforeach
</flux:menu.group>

<flux:menu.separator />

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
