<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Block any authenticated user whose account has been deactivated, so a
     * deactivation takes effect immediately regardless of how they signed in.
     * Token-authenticated API/MCP requests get a 403 (rendered as JSON); web
     * sessions are signed out and redirected to login.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isDeactivated()) {
            if ($request->is('api/*', 'mcp')) {
                abort(403, __('Your account has been deactivated.'));
            }

            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('Your account has been deactivated.'),
            ]);
        }

        return $next($request);
    }
}
