<?php

namespace App\Livewire\Notifications;

use App\Enums\DeliveryMode;
use App\Models\User;
use App\Notifications\Concerns\OptsIntoMail;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The delivery tab: whether notifications also arrive by e-mail, and for which
 * kind of item.
 *
 * In-app notifications are not configurable here — they are the product, and
 * silencing them belongs to the subscription itself ({@see SubscriptionSettings}).
 * This screen only adds a second channel on top, off for everyone until asked
 * for, which is what {@see OptsIntoMail} reads.
 */
class DeliverySettings extends Component
{
    /**
     * The master switch: e-mail on top of the in-app notifications, or not.
     */
    public bool $email = false;

    /**
     * How e-mail is delivered — as it happens, or collected into a digest. A
     * {@see DeliveryMode} value; meaningful only while {@see $email} is on.
     */
    public string $mode = 'immediate';

    /**
     * Per-level opt-in, meaningful only while {@see $email} is on. Both default
     * on so switching e-mail on delivers everything the user follows, and each
     * can then be turned off to narrow it.
     */
    public bool $emailProjects = true;

    public bool $emailTasks = true;

    public function mount(): void
    {
        $user = Auth::user();

        $this->email = (bool) $user->preference(User::EMAIL_PREFERENCE_KEY, false);
        $this->mode = $user->deliveryMode()->value;
        $this->emailProjects = (bool) $user->preference(User::EMAIL_PROJECTS_PREFERENCE_KEY, true);
        $this->emailTasks = (bool) $user->preference(User::EMAIL_TASKS_PREFERENCE_KEY, true);
    }

    public function updatedEmail(bool $value): void
    {
        $this->persist(User::EMAIL_PREFERENCE_KEY, $value);
    }

    /**
     * The delivery mode is validated against the enum rather than trusted: the
     * radio is user-writable state like any other Livewire property.
     */
    public function updatedMode(string $value): void
    {
        $mode = DeliveryMode::tryFrom($value);

        if ($mode === null) {
            $this->mode = Auth::user()->deliveryMode()->value;

            return;
        }

        Auth::user()->setPreference(User::EMAIL_MODE_PREFERENCE_KEY, $mode->value);

        Flux::toast(text: __('Notification settings saved.'), variant: 'success');
    }

    /**
     * The delivery modes offered by the radio.
     *
     * @return array<int, DeliveryMode>
     */
    public function modes(): array
    {
        return DeliveryMode::cases();
    }

    public function updatedEmailProjects(bool $value): void
    {
        $this->persist(User::EMAIL_PROJECTS_PREFERENCE_KEY, $value);
    }

    public function updatedEmailTasks(bool $value): void
    {
        $this->persist(User::EMAIL_TASKS_PREFERENCE_KEY, $value);
    }

    /**
     * Whether the signed-in user's address has been confirmed. Mail is never
     * sent to an unverified address, so the screen says so rather than letting
     * someone switch e-mail on and wonder why nothing arrives.
     */
    public function isVerified(): bool
    {
        return Auth::user()->email_verified_at !== null;
    }

    public function render(): View
    {
        return view('livewire.notifications.delivery-settings');
    }

    private function persist(string $key, bool $value): void
    {
        Auth::user()->setPreference($key, $value);

        Flux::toast(text: __('Notification settings saved.'), variant: 'success');
    }
}
