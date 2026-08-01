{{--
    Override of Flux's flux:command.items. Identical to the package's published
    stub, but kept here so the empty-state label resolves through __() and gets a
    German translation (KAN-195) — Flux's built-in default renders a hardcoded
    English "No results found". Re-sync with the vendor stub on Flux upgrades.
--}}
@blaze(fold: true)

@php
    $classes = Flux::classes()
        ->add('p-[.3125rem]')
        ->add('overflow-y-auto')
        ->add('bg-white dark:bg-zinc-700');
@endphp

<ui-options {{ $attributes->class($classes) }} data-flux-command-items>
    {{ $slot }}

    <flux:command.empty>{!! __('No results found') !!}</flux:command.empty>
</ui-options>
