<?php

namespace App\Notifications\Concerns;

use App\Enums\DeliveryMode;
use App\Models\User;

/**
 * Per-recipient opt-in for the mail channel.
 *
 * The opt-in itself is {@see User::EMAIL_PREFERENCE_KEY} in the user's
 * preferences.
 *
 * E-mail is off for everybody until they ask for it: this returns no channels
 * unless the recipient has switched it on, so shipping a mail-capable
 * notification never starts mailing anyone. The preference itself is written by
 * the notification settings (KAN-20); until that UI lands the key can only be
 * set programmatically, which is the intended state — the plumbing ships first.
 *
 * The language a mail is written in comes from the recipient's stored locale
 * ({@see User::preferredLocale()}), since a queued mail has no session to read
 * the interface language from.
 *
 * Mail is only ever sent to a confirmed address. Every app route sits behind the
 * `verified` middleware, so a user who can reach the setting is verified anyway;
 * the check is here so a seeded or imported account cannot be mailed by accident.
 */
trait OptsIntoMail
{
    /**
     * The channels this notification goes out on for the given recipient —
     * `['mail']` once they have opted in, and nothing at all otherwise. An empty
     * list means Laravel never queues a job for them.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->wantsMail($notifiable) ? ['mail'] : [];
    }

    /**
     * Whether this recipient has asked to be e-mailed and can be.
     *
     * The master switch decides first, then the delivery mode — a digest
     * subscriber is mailed by the digest command, not from here — and finally
     * the per-level narrowing ({@see mailLevelKey()}), which lets someone follow
     * their own tasks by mail without hearing about every project-level change.
     */
    protected function wantsMail(object $notifiable): bool
    {
        if (! $notifiable instanceof User
            || $notifiable->email_verified_at === null
            || ! $notifiable->preference(User::EMAIL_PREFERENCE_KEY, false)) {
            return false;
        }

        // A digest subscriber gets nothing as it happens — the scheduled digest
        // is their whole delivery.
        if ($notifiable->deliveryMode() !== DeliveryMode::Immediate) {
            return false;
        }

        $level = $this->mailLevelKey();

        return $level === null || (bool) $notifiable->preference($level, true);
    }

    /**
     * The per-level preference this notification is governed by, or null when it
     * is not narrowed by level at all. Notifications override it.
     */
    protected function mailLevelKey(): ?string
    {
        return null;
    }
}
