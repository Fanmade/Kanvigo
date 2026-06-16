<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

trait HasSubscribers
{
    /**
     * Users subscribed to notifications for this item.
     *
     * @return MorphToMany<User, $this>
     */
    public function subscribers(): MorphToMany
    {
        return $this->morphToMany(User::class, 'subscribable', 'subscriptions')->withTimestamps();
    }

    public function isSubscribedBy(User $user): bool
    {
        return $this->subscribers()->whereKey($user->id)->exists();
    }

    public function subscribe(User $user): void
    {
        $this->subscribers()->syncWithoutDetaching([$user->id]);
    }

    public function unsubscribe(User $user): void
    {
        $this->subscribers()->detach($user->id);
    }

    /**
     * The users who should be notified about an update to this item.
     * Defaults to its direct subscribers; models override to add ancestors.
     *
     * @return Collection<int, User>
     */
    public function notificationAudience(): Collection
    {
        return $this->subscribers()->get();
    }
}
