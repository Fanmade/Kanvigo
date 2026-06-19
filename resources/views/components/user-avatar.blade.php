@props([
    'user' => null,
    'name' => null,
    'size' => 'xs',
])

{{--
    Renders a user's uploaded avatar, falling back to their initials when they
    have none. Pass a `:user` to show their picture; `:name` overrides the label
    used for the initials fallback (e.g. for deleted or system authors with no
    user record).
--}}
<flux:avatar
    :size="$size"
    :src="$user?->avatarUrl()"
    :name="$name ?? $user?->name"
    {{ $attributes }}
/>
