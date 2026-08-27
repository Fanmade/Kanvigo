{{-- Shown for the whole of an impersonation window, so the session you are
     looking at is never mistaken for your own.

     Rendered inside <flux:main> by the app layout. It must not be a direct child
     of <body>: Flux's grid gives the content column to [data-flux-main] alone, so
     a bare sibling lands in the min-content `aside` track (KAN-579). Nor may it
     sit between the sidebar and the header, whose adjacency selector drives the
     full-height sidebar (KAN-330). Inside main it is clear of both, and main's
     own padding is why this only adds a bottom margin.

     The actions override centres the "Stop impersonating" button against the
     text: Flux pins a callout's actions with `self-start`, which reads as
     misaligned on a single-line inline callout. A descendant selector wins on
     specificity — a plain `self-center` on the slot would not, since Tailwind
     emits `.self-start` after `.self-center`. --}}
@if (\App\Support\Impersonation::isImpersonating())
    <div class="[&_[data-slot=actions]]:self-center mb-6" data-test="impersonation-banner">
        <flux:callout variant="warning" icon="eye" inline>
            <flux:callout.text>
                {{ __('Viewing the app as :name.', ['name' => auth()->user()->name]) }}
            </flux:callout.text>

            <x-slot name="actions">
                <form method="POST" action="{{ route('impersonation.stop') }}">
                    @csrf
                    <flux:button size="sm" type="submit" data-test="stop-impersonating-banner">
                        {{ __('Stop impersonating') }}
                    </flux:button>
                </form>
            </x-slot>
        </flux:callout>
    </div>
@endif
