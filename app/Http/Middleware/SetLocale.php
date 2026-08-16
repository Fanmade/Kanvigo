<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * The locales the application supports, each mapped to its name in its own
     * language — so a switcher reads the same whichever locale is active.
     *
     * @var array<string, string>
     */
    public const array SUPPORTED = [
        'en' => 'English',
        'de' => 'Deutsch',
    ];

    /**
     * Resolve the active locale from the session, falling back to the
     * browser's Accept-Language header and finally the app default.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(self::SUPPORTED);

        $locale = $request->session()->get('locale')
            ?? $request->getPreferredLanguage($supported)
            ?? config('app.locale');

        if (in_array($locale, $supported, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
