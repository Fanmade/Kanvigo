{{-- Shown for the whole of an impersonation window, so the session you are
     looking at is never mistaken for your own. Sits below the header: Flux's
     full-height-sidebar grid keys off the `[data-flux-sidebar]+[data-flux-header]`
     adjacent-sibling selector, so anything wedged between the two drops the
     header to a full-width bar above the sidebar (KAN-330). --}}
@if (\App\Support\Impersonation::isImpersonating())
    <div class="px-4 pt-4 lg:px-6" data-test="impersonation-banner">
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
