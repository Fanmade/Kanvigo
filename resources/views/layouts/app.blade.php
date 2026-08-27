<x-layouts::app.sidebar :title="$title ?? null">
    {{-- The banner belongs inside <flux:main>, not beside it: Flux lays the body
         out as a named grid (sidebar / header / main / aside) and only
         [data-flux-main] claims the content column, so a sibling with no
         grid-area is auto-placed into the min-content `aside` track and comes
         out as a ~66px sliver in the top-right corner (KAN-579). --}}
    <flux:main>
        <x-impersonation-banner />

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
