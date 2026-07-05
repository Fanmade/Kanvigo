<?php

namespace App\Audit\Listeners;

use App\Models\User;
use App\Support\Facades\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * Subscribes to the framework, Fortify and passkey authentication events and
 * records each as an audit event. Authn/Security events are not feed-worthy,
 * so they bypass the activity feed and flow to the outbox and any registered
 * compliance/transport sinks only.
 *
 * The actor is taken from the event's user (not the guard): at Logout time the
 * guard is already cleared, and a Failed attempt never had one.
 */
class RecordAuthenticationEvents
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'recordLogin']);
        $events->listen(Logout::class, [self::class, 'recordLogout']);
        $events->listen(Failed::class, [self::class, 'recordFailedLogin']);
        $events->listen(Lockout::class, [self::class, 'recordLockout']);
        $events->listen(PasswordReset::class, [self::class, 'recordPasswordReset']);
        $events->listen(Verified::class, [self::class, 'recordEmailVerified']);

        $events->listen(TwoFactorAuthenticationEnabled::class, [self::class, 'recordTwoFactorEnabled']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'recordTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'recordTwoFactorDisabled']);
        $events->listen(TwoFactorAuthenticationChallenged::class, [self::class, 'recordTwoFactorChallenged']);
        $events->listen(TwoFactorAuthenticationFailed::class, [self::class, 'recordTwoFactorFailed']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'recordRecoveryCodesGenerated']);
        $events->listen(RecoveryCodeReplaced::class, [self::class, 'recordRecoveryCodeReplaced']);

        $events->listen(PasskeyRegistered::class, [self::class, 'recordPasskeyRegistered']);
        $events->listen(PasskeyDeleted::class, [self::class, 'recordPasskeyDeleted']);
        $events->listen(PasskeyVerified::class, [self::class, 'recordPasskeyVerified']);
    }

    public function recordLogin(Login $event): void
    {
        Audit::record($this->userEvent('login', $event->user));
    }

    public function recordLogout(Logout $event): void
    {
        if ($event->user !== null) {
            Audit::record($this->userEvent('logout', $event->user));
        }
    }

    public function recordFailedLogin(Failed $event): void
    {
        $failed = AuditEvent::make('login_failed', AuditCategory::Authn)
            ->withMetadata(array_filter([
                'email' => $event->credentials['email'] ?? null,
            ]));

        if ($event->user !== null) {
            $failed = $failed
                ->withSubject($this->morphClass($event->user), $event->user->getAuthIdentifier());
        }

        Audit::record($failed);
    }

    public function recordLockout(Lockout $event): void
    {
        Audit::record(AuditEvent::make('lockout', AuditCategory::Security)
            ->withMetadata(array_filter([
                'email' => $event->request->input(Fortify::username()),
            ])));
    }

    public function recordPasswordReset(PasswordReset $event): void
    {
        Audit::record($this->userEvent('password_reset', $event->user));
    }

    public function recordEmailVerified(Verified $event): void
    {
        /** @var Authenticatable $user The verified user is always the authenticated User model. */
        $user = $event->user;

        Audit::record($this->userEvent('email_verified', $user));
    }

    public function recordTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        Audit::record($this->userEvent('two_factor_enabled', $event->user));
    }

    public function recordTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        Audit::record($this->userEvent('two_factor_confirmed', $event->user));
    }

    public function recordTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        Audit::record($this->userEvent('two_factor_disabled', $event->user));
    }

    public function recordTwoFactorChallenged(TwoFactorAuthenticationChallenged $event): void
    {
        Audit::record($this->userEvent('two_factor_challenged', $event->user));
    }

    public function recordTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        Audit::record($this->userEvent('two_factor_failed', $event->user));
    }

    public function recordRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        Audit::record($this->userEvent('recovery_codes_generated', $event->user));
    }

    public function recordRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        Audit::record($this->userEvent('recovery_code_replaced', $event->user));
    }

    public function recordPasskeyRegistered(PasskeyRegistered $event): void
    {
        Audit::record($this->userEvent('passkey_registered', $event->user)
            ->withMetadata(['passkey_id' => $event->passkey->getKey(), 'passkey' => $event->passkey->name]));
    }

    public function recordPasskeyDeleted(PasskeyDeleted $event): void
    {
        Audit::record($this->userEvent('passkey_deleted', $event->user)
            ->withMetadata(['passkey_id' => $event->passkey->getKey(), 'passkey' => $event->passkey->name]));
    }

    public function recordPasskeyVerified(PasskeyVerified $event): void
    {
        Audit::record($this->userEvent('passkey_verified', $event->user)
            ->withMetadata(['passkey_id' => $event->passkey->getKey(), 'passkey' => $event->passkey->name]));
    }

    /**
     * An Authn event with the given user as both its subject and actor.
     */
    protected function userEvent(string $action, Authenticatable $user): AuditEvent
    {
        return AuditEvent::make($action, AuditCategory::Authn)
            ->withSubject($this->morphClass($user), $user->getAuthIdentifier())
            ->withActor($user->getAuthIdentifier());
    }

    protected function morphClass(Authenticatable $user): string
    {
        return $user instanceof User ? $user->getMorphClass() : $user::class;
    }
}
