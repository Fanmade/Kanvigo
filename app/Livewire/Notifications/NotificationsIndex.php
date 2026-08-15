<?php

namespace App\Livewire\Notifications;

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The notifications page: the inbox (the notifications themselves) and the
 * subscriptions (the items they come from), kept apart as two tabs.
 */
#[Title('Notifications')]
class NotificationsIndex extends Component
{
    #[Url]
    public string $tab = 'inbox';

    public function render(): mixed
    {
        return view('livewire.notifications.notifications-index');
    }
}
