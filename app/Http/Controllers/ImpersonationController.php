<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Starts and stops acting as another account, from user administration.
 *
 * A plain POST endpoint rather than a Livewire action: the swap regenerates the
 * session id and with it the CSRF token, which a component mid-request cannot
 * hand back to the page it came from. A form post followed by a redirect lands
 * on a freshly rendered page with a matching token.
 */
class ImpersonationController extends Controller
{
    public function store(User $user): RedirectResponse
    {
        $administrator = Auth::user();

        Gate::authorize('impersonate', $user);

        Impersonation::start($administrator, $user);

        return redirect()->route('dashboard');
    }

    public function destroy(): RedirectResponse
    {
        return Impersonation::stop() === null
            ? redirect()->route('dashboard')
            : redirect()->route('admin.users');
    }
}
