<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Notification subscriptions ("watches") for an item.
 *
 * A watch is either explicit — the user pressed the bell — or automatic,
 * set by a trigger that implies interest: creating the item, being assigned
 * to it, commenting on it or being @mentioned in it.
 *
 * Unsubscribing does not delete the row, it stamps `unsubscribed_at`. That
 * tombstone is what makes "leave me alone" stick: an automatic trigger skips
 * a user who has already opted out, while an explicit {@see subscribe()}
 * (the user asking again by hand) clears it. Reads go through the filtered
 * {@see subscribers()} relation, so an opted-out user is invisible to the
 * notification audience.
 *
 * @phpstan-require-extends Model
 */
trait HasSubscribers
{
    public static function bootHasSubscribers(): void
    {
        static::created(static function (Model $model): void {
            /** @var Model&self $model */
            $actorId = Auth::id();

            if ($actorId !== null && $model->autoSubscribesCreator()) {
                $model->autoSubscribe([(int) $actorId]);
            }
        });
    }

    /**
     * Users subscribed to notifications for this item. Excludes anyone who has
     * unsubscribed, so every read path — the audience, the bell state, the
     * settings list — sees the same set.
     *
     * @return MorphToMany<User, $this>
     */
    public function subscribers(): MorphToMany
    {
        return $this->subscriptionRecords()->wherePivotNull('unsubscribed_at');
    }

    /**
     * The raw subscription rows, opted-out ones included. Writes go through
     * here: the filtered relation would report an unsubscribed user as absent
     * and try to insert a second, unique-constraint-violating row for them.
     *
     * @return MorphToMany<User, $this>
     */
    public function subscriptionRecords(): MorphToMany
    {
        return $this->morphToMany(User::class, 'subscribable', 'subscriptions')
            ->withPivot(['auto', 'unsubscribed_at'])
            ->withTimestamps();
    }

    public function isSubscribedBy(User $user): bool
    {
        return $this->subscribers()->whereKey($user->id)->exists();
    }

    /**
     * Subscribe on the user's own request. This is the one path that overrides
     * an earlier unsubscribe — asking by hand beats having opted out before.
     */
    public function subscribe(User $user): void
    {
        $this->subscriptionRecords()->syncWithoutDetaching([
            $user->id => ['auto' => false, 'unsubscribed_at' => null],
        ]);
    }

    /**
     * Stop notifying the user, keeping the row as a record of the decision.
     * A user who was never subscribed gets no tombstone — there is nothing to
     * remember, and inventing one would silently block later auto-subscribes.
     */
    public function unsubscribe(User $user): void
    {
        $this->subscriptionRecords()->updateExistingPivot($user->id, ['unsubscribed_at' => Carbon::now()]);
    }

    /**
     * Subscribe users because they got involved (created, assigned, commented,
     * mentioned) — skipping anyone who has unsubscribed before, so the same
     * trigger cannot undo their decision. Users already subscribed keep the
     * subscription they have; an explicit one is never downgraded to automatic.
     *
     * @param  iterable<int|string|User>  $users
     */
    public function autoSubscribe(iterable $users): void
    {
        $ids = collect($users)
            ->map(static fn (int|string|User $user): int => $user instanceof User ? $user->id : (int) $user)
            ->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $known = $this->subscriptionRecords()->whereKey($ids)->pluck('users.id');

        $ids->diff($known)->each(function (int $id): void {
            $this->subscriptionRecords()->attach($id, ['auto' => true]);
        });
    }

    /**
     * Whether creating this item subscribes its creator. Items override this to
     * opt out where a watch would be too broad to be useful.
     */
    public function autoSubscribesCreator(): bool
    {
        return true;
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
