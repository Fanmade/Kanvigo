<?php

use App\Http\Middleware\EnsureTokenCanWrite;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use App\Support\Facades\Audit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Laravel\Fortify\Fortify;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            EnsureUserIsActive::class,
        ]);

        // Token-authenticated surfaces must also reject deactivated accounts —
        // a personal access token keeps working after the web session is killed.
        $middleware->api(append: [
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'token.write' => EnsureTokenCanWrite::class,
        ]);
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request) => $request->is('api/*', 'mcp'),
        );

        // The login limiter throttles at the route middleware, so Fortify's
        // Lockout event never fires — record auth lockouts from the throttle
        // rejection instead. Returning null keeps the default 429 rendering.
        $exceptions->render(static function (ThrottleRequestsException $exception, Request $request): mixed {
            if ($request->routeIs('login.store', 'two-factor.login.store', 'passkey.login')) {
                Audit::record(AuditEvent::make('lockout', AuditCategory::Security)
                    ->withMetadata(array_filter(['email' => $request->input(Fortify::username())])));
            }

            return null;
        });
    })->create();
