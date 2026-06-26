<?php

namespace App\Concerns;

use App\Contracts\Subscribable;
use App\Enums\RelationshipType;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ItemActivity;
use App\Support\BoardCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

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
            'token_name' => $this->currentTokenName(),
            'action' => $action,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);

        // Tag/assignee/dependency changes don't touch the task row (so the
        // saved() board-cache hook doesn't fire) but do change how its card
        // renders — invalidate here. Comments don't appear on the board.
        if ($this instanceof Task && $action !== 'commented') {
            BoardCache::touch($this->project_id);
        }

        $this->notifySubscribers($activity);

        return $activity;
    }

    /**
     * The name of the API/MCP token the current action is being performed with,
     * or null when it is a direct web-session action. A transient (session)
     * token has no name, so only real personal access tokens are attributed.
     */
    protected function currentTokenName(): ?string
    {
        $user = Auth::user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token->name : null;
    }

    /**
     * Record an assignee change, capturing the names of the users added and
     * removed as a JSON snapshot. Names are stored (rather than IDs) so the
     * trail reflects who was assigned at the time, even after a later rename or
     * deletion. Returns null when nothing actually changed.
     *
     * @param  array<int, int|string>  $attachedIds  user IDs newly assigned
     * @param  array<int, int|string>  $detachedIds  user IDs unassigned
     */
    public function recordAssigneeChange(array $attachedIds, array $detachedIds): ?Activity
    {
        if ($attachedIds === [] && $detachedIds === []) {
            return null;
        }

        $names = User::query()
            ->whereIn('id', array_merge($attachedIds, $detachedIds))
            ->pluck('name', 'id');

        $resolve = static fn (array $ids): array => collect($ids)
            ->map(static fn ($id) => $names[(int) $id] ?? null)
            ->filter()
            ->values()
            ->all();

        $added = $resolve($attachedIds);
        $removed = $resolve($detachedIds);

        return $this->recordActivity(
            'assignee_changed',
            'assignees',
            $removed === [] ? null : json_encode($removed, JSON_THROW_ON_ERROR),
            $added === [] ? null : json_encode($added, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Record a tag change, capturing the names of the tags added and removed as
     * a JSON snapshot (added in new_value, removed in old_value). Returns null
     * when nothing actually changed.
     *
     * @param  array<int, string>  $addedNames
     * @param  array<int, string>  $removedNames
     */
    public function recordTagChange(array $addedNames, array $removedNames): ?Activity
    {
        if ($addedNames === [] && $removedNames === []) {
            return null;
        }

        return $this->recordActivity(
            'tags_changed',
            'tags',
            $removedNames === [] ? null : json_encode(array_values($removedNames), JSON_THROW_ON_ERROR),
            $addedNames === [] ? null : json_encode(array_values($addedNames), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Record a relationship link being added or removed. The relationship keyword
     * (e.g. "blocked_by", "duplicates", "relates") and the related reference are
     * captured from this item's perspective, so the trail can read "is now
     * blocked by KAN-3" or "no longer duplicates KAN-2".
     *
     * @param  bool  $linked  true when the link was added, false when removed
     * @param  string  $direction  the relationship keyword from this item's perspective ({@see RelationshipType::keywords()})
     * @param  string  $reference  the related item's reference
     */
    public function recordDependencyChange(bool $linked, string $direction, string $reference): Activity
    {
        $payload = json_encode(['direction' => $direction, 'reference' => $reference], JSON_THROW_ON_ERROR);

        return $this->recordActivity(
            'dependency_changed',
            'dependencies',
            $linked ? null : $payload,
            $linked ? $payload : null,
        );
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
