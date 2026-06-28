@props(['user' => null])

{{--
    Wraps its content in a link to the given user's profile page. Falls back to
    rendering the content unlinked when there is no user (a deleted or system
    author), so callers don't need to branch.
--}}
@if ($user)
    <a href="{{ route('users.show', $user) }}" wire:navigate {{ $attributes->merge(['class' => 'hover:underline']) }}>
        {{ $slot }}
    </a>
@else
    {{ $slot }}
@endif
