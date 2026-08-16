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

        return back();
    }
}
