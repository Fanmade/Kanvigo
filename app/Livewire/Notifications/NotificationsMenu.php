<?php

namespace App\Livewire\Notifications;

use App\Livewire\Notifications\Concerns\DescribesNotifications;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @property-read int $unreadCount
 */
class NotificationsMenu extends Component
{
    use DescribesNotifications;

    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotificationCount();
    }

    /**
     * The unread-count label shown on the menu badge, capped at "9+", or null
     * when there is nothing unread and the badge should be hidden.
     */
    #[Computed]
    public function unreadBadge(): ?string
    {
        $count = $this->unreadCount;

        if ($count === 0) {
            return null;
        }

        return $count > 9 ? '9+' : (string) $count;
    }

    /**
     * The panel's list: unread first, then the most recent read ones, so it
     * drains on its own as notifications are read and dismissed.
     *
     * @return Collection<int, Notification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        return Auth::user()->notifications()
            ->reorder()
            // `read_at is null desc` is not portable; a case expression sorts
            // unread (0) before read (1) on every supported driver.
            ->orderByRaw('case when read_at is null then 0 else 1 end')
            ->latest()
            ->limit(10)
            ->get();
    }

    public function markAllRead(): void
    {
        $user = Auth::user();

        // Mark them read in one statement instead of hydrating every unread model.
        // A bulk update fires no model events, so the cached unread count (which is
        // busted by DatabaseNotification's `updated` event) must be cleared by hand.
        $user->unreadNotifications()->update(['read_at' => now()]);
        User::forgetUnreadNotificationCount($user->getKey());

        unset($this->unreadCount, $this->unreadBadge, $this->notifications);
    }

    /**
     * Dismiss one notification: it is soft-deleted, so it leaves the panel and
     * the unread count but stays in the inbox archive until it is pruned. The
     * lookup is scoped to the caller's own notifications, so a tampered id can
     * never dismiss someone else's.
     */
    public function dismiss(string $id): void
    {
        Auth::user()->notifications()->whereKey($id)->first()?->delete();

        unset($this->unreadCount, $this->unreadBadge, $this->notifications);
    }

    /**
     * Dismiss every notification the user has.
     */
    public function clearAll(): void
    {
        $user = Auth::user();

        // As with markAllRead: a bulk soft-delete fires no model events, so the
        // cached unread count must be busted by hand.
        $user->notifications()->delete();
        User::forgetUnreadNotificationCount($user->getKey());

        unset($this->unreadCount, $this->unreadBadge, $this->notifications);
    }

    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->whereKey($id)->first();
        $notification?->markAsRead();

        $url = $notification?->data['url'] ?? null;

        unset($this->unreadCount, $this->unreadBadge, $this->notifications);

        if (is_string($url)) {
            $this->redirect($url, navigate: true);
        }
    }
}
