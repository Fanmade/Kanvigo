@props(['name'])

{{-- A single project role rendered as a colored pill. The four seeded roles
     (owner, admin, member, viewer) each carry a fixed color; any other name is
     one of the project's custom roles and reads purple, so custom roles stand
     apart from the base set at a glance. Pass a <flux:badge.close> in the slot
     to make the chip removable. --}}
@php
    $roleColors = [
        'owner' => 'amber',
        'admin' => 'blue',
        'member' => 'emerald',
        'viewer' => 'zinc',
    ];
    $color = $roleColors[$name] ?? 'purple';
@endphp

<flux:badge size="sm" :color="$color" variant="pill" {{ $attributes }}>
    {{ \Illuminate\Support\Str::headline($name) }}{{ $slot }}
</flux:badge>
