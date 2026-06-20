@props(['color' => 'zinc'])

{{--
    A small colored dot for a tag. The color → class map is written out in full
    (rather than an interpolated `bg-{$color}-500`) so Tailwind's JIT compiler
    keeps the classes — the same approach Flux uses for its badge colors.
--}}
@php
    $background = match ($color) {
        'red' => 'bg-red-500',
        'orange' => 'bg-orange-500',
        'amber' => 'bg-amber-500',
        'yellow' => 'bg-yellow-500',
        'lime' => 'bg-lime-500',
        'green' => 'bg-green-500',
        'emerald' => 'bg-emerald-500',
        'teal' => 'bg-teal-500',
        'cyan' => 'bg-cyan-500',
        'sky' => 'bg-sky-500',
        'blue' => 'bg-blue-500',
        'indigo' => 'bg-indigo-500',
        'violet' => 'bg-violet-500',
        'purple' => 'bg-purple-500',
        'fuchsia' => 'bg-fuchsia-500',
        'pink' => 'bg-pink-500',
        'rose' => 'bg-rose-500',
        default => 'bg-zinc-400',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-block size-2 shrink-0 rounded-full {$background}"]) }}></span>
