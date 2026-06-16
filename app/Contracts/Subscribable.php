<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;

interface Subscribable
{
    /**
     * The users who should be notified about an update to this item.
     *
     * @return Collection<int, User>
     */
    public function notificationAudience(): Collection;
}
