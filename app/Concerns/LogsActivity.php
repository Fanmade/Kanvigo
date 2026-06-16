<?php

namespace App\Concerns;

use App\Contracts\Subscribable;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\ItemActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(
            static function (Model $model): void {
                /** @var Model&self $model */
                $model->recordActivity('created');
            });
    }

    /**
     * The audit-trail entries recorded for this model.
     *
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest();
    }

    /**
     * Record an audit-trail entry for this model.
     */
    public function recordActivity(string $action, ?string $field = null, ?string $oldValue = null, ?string $newValue = null): Activity
    {
        $activity = $this->activities()->create([
            'user_id' => Auth::id(),
            'action' => $action,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);

        $this->notifySubscribers($activity);

        return $activity;
    }

    /**
     * Notify the item's subscribers (excluding the actor) about an update.
     */
    protected function notifySubscribers(Activity $activity): void
    {
        if (! $this instanceof Subscribable) {
            return;
        }

        $actorId = Auth::id();

        $this->notificationAudience()
            ->unique('id')
            ->reject(static fn (User $user) => $user->id === $actorId)
            ->each(static fn (User $user) => $user->notify(new ItemActivity($activity)));
    }
}
