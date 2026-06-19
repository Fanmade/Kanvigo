<?php

namespace App\Livewire\Notifications;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class NotificationsMenu extends Component
{
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /**
     * The unread-count label shown on the menu badge, capped at "9+", or null
     * when there is nothing unread and the badge should be hidden.
     */
    #[Computed]
    public function unreadBadge(): ?string
    {
        $count = $this->unreadCount();

        if ($count === 0) {
            return null;
        }

        return $count > 9 ? '9+' : (string) $count;
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        return Auth::user()->notifications()->latest()->limit(10)->get();
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();

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
