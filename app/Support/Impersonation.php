<?php

namespace App\Support;

use App\Audit\ImpersonationAudit;
use App\Audit\Listeners\RecordAuthenticationEvents;
use App\Livewire\Settings\DeleteUserForm;
use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Lets an administrator act as another account for a while, so a feature can be
 * exercised with that person's real permissions instead of being reasoned about.
 *
 * The swap is deliberately hand-rolled rather than delegated to the guard's own
 * login()/logout() pair, because both have side effects that would be wrong here:
 *
 * - logout() cycles a non-empty remember token (see {@see DeleteUserForm}),
 *   so stopping an impersonation would silently sign the impersonated person out
 *   of their own devices.
 * - login() fires the Login event, and {@see RecordAuthenticationEvents}
 *   would record a sign-in that never happened.
 *
 * Writing the guard's session key by hand and calling setUser() does the identity
 * swap without either. The session is regenerated — not invalidated — so the id
 * and CSRF token rotate while the locale and the marker below survive.
 */
class Impersonation
{
    /**
     * The impersonating administrator's own id. Its presence in the session is
     * what "I am currently impersonating" means.
     */
    public const string SESSION_KEY = 'impersonator_id';

    /**
     * The administrator's parked password confirmation. It is taken out of the
     * session for the duration so the impersonated session starts unconfirmed —
     * password-confirmed surfaces (Security settings, API tokens) then stay out
     * of reach, since the impersonator cannot supply the target's password — and
     * is handed back when they return to themselves.
     */
    public const string PASSWORD_CONFIRMED_KEY = 'impersonator_password_confirmed_at';

    /**
     * Laravel's own key for the password confirmation timestamp.
     */
    private const string AUTH_PASSWORD_CONFIRMED_KEY = 'auth.password_confirmed_at';

    /**
     * Begin impersonating the target, recording who started it.
     */
    public static function start(User $administrator, User $target): void
    {
        // Recorded before the marker goes in, so the event that opens the window
        // does not itself carry the "acted under impersonation" stamping that
        // ContextResolver adds to everything inside it.
        Audit::record(ImpersonationAudit::started($administrator, $target));

        Session::put(self::SESSION_KEY, $administrator->getKey());
        Session::put(self::PASSWORD_CONFIRMED_KEY, Session::get(self::AUTH_PASSWORD_CONFIRMED_KEY));
        Session::forget(self::AUTH_PASSWORD_CONFIRMED_KEY);

        self::becomeUser($target);
    }

    /**
     * Return to the impersonating administrator, if there is one. Returns the
     * account handed back, or null when nothing was being impersonated.
     */
    public static function stop(): ?User
    {
        $administrator = self::impersonator();

        if ($administrator === null) {
            Session::forget([self::SESSION_KEY, self::PASSWORD_CONFIRMED_KEY]);

            return null;
        }

        $target = Auth::user();

        $confirmedAt = Session::get(self::PASSWORD_CONFIRMED_KEY);

        Session::forget([self::SESSION_KEY, self::PASSWORD_CONFIRMED_KEY]);

        if ($confirmedAt !== null) {
            Session::put(self::AUTH_PASSWORD_CONFIRMED_KEY, $confirmedAt);
        }

        self::becomeUser($administrator);

        if ($target instanceof User) {
            Audit::record(ImpersonationAudit::stopped($administrator, $target));
        }

        return $administrator;
    }

    public static function isImpersonating(): bool
    {
        return self::impersonatorId() !== null;
    }

    /**
     * The id of the administrator behind the current session, if impersonating.
     */
    public static function impersonatorId(): ?int
    {
        // Queue workers and console commands have no session data — the store
        // resolves there but holds nothing, so this reads as "not impersonating"
        // rather than throwing. The audit stamping asks on every path.
        $id = Session::get(self::SESSION_KEY);

        return is_int($id) ? $id : null;
    }

    /**
     * The administrator behind the current session, if impersonating.
     */
    public static function impersonator(): ?User
    {
        $id = self::impersonatorId();

        return $id === null ? null : User::find($id);
    }

    /**
     * Swap the session's identity without touching remember tokens or firing
     * authentication events. Mirrors what the guard's own updateSession() does.
     */
    private static function becomeUser(User $user): void
    {
        Session::put(Auth::guard('web')->getName(), $user->getKey());
        Session::regenerate();

        Auth::guard('web')->setUser($user);
    }
}
