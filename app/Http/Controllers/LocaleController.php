<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switches the interface language from the account menu.
 *
 * The locale is a server-side setting — unlike the theme it cannot be applied
 * in the browser — so this stores the choice the same way the Appearance
 * settings page does and sends the user back to re-render the page in it.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', Rule::in(array_keys(SetLocale::SUPPORTED))],
        ]);

        $request->session()->put('locale', $validated['locale']);

        // Mirrored onto the user so notifications sent outside a request — a
        // queued mail, a scheduled reminder — are written in the same language
        // ({@see \App\Models\User::preferredLocale()}).
        $request->user()?->setPreference('locale', $validated['locale']);

        return back();
    }
}
