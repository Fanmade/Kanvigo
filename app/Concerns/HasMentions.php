<?php

namespace App\Concerns;

use App\Contracts\Mentionable;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\UserMentioned;
use App\Support\MentionParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Auth;

/**
 * Maintains a normalized index of the users @mentioned in a model's rich-text
 * content (a task/project description or a comment body), so notifications and
 * other features can read who was mentioned without re-parsing HTML.
 *
 * The index is reconciled on every save from the stored, sanitized HTML — the
 * one place all write paths (Livewire editor, MCP/API) converge — and is limited
 * to the users the model permits to be mentioned, so content written directly
 * through the API cannot mention users without access.
 *
 * @see MentionParser
 * @see Mentionable
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Mentionable
 */
trait HasMentions
{
    public static function bootHasMentions(): void
    {
        static::saved(static function (Model $model): void {
            /** @var Model&Mentionable $model */
            if ($model->wasRecentlyCreated || $model->wasChanged('description') || $model->wasChanged('body')) {
                $model->syncMentions();
            }
        });
    }

    /**
     * The users currently @mentioned in this model's content.
     *
     * @return MorphToMany<User, $this>
     */
    public function mentions(): MorphToMany
    {
        return $this->morphToMany(User::class, 'mentionable', 'mentions')->withTimestamps();
    }

    /**
     * Reconcile the stored mention index with the @mentions in the current
     * content, dropping any mention of a user who may not be mentioned here.
     * Returns the sync changes (attached/detached/updated user ids) so callers
     * can act on the users who were newly mentioned.
     *
     * @return array{attached: list<int>, detached: list<int>, updated: list<int>}
     */
    public function syncMentions(): array
    {
        $mentioned = MentionParser::userIds($this->mentionContent());

        // Only resolve the (DB-backed) mentionable set when there is something to
        // match; an empty list still syncs, detaching any previously-stored mentions.
        $allowed = $mentioned === []
            ? []
            : array_values(array_intersect($mentioned, $this->mentionableUserIds()));

        $changes = $this->mentions()->sync($allowed);

        $attached = array_values(array_map('intval', $changes['attached']));

        $this->notifyNewlyMentioned($attached);

        return [
            'attached' => $attached,
            'detached' => array_values(array_map('intval', $changes['detached'])),
            'updated' => array_values(array_map('intval', $changes['updated'])),
        ];
    }

    /**
     * Notify the users newly @mentioned (excluding the actor — you don't get
     * pinged for mentioning yourself) and auto-subscribe them to the surrounding
     * item, mirroring how assigning a user subscribes them.
     *
     * @param  list<int>  $attachedIds
     */
    protected function notifyNewlyMentioned(array $attachedIds): void
    {
        $actorId = Auth::id();
        $recipientIds = array_values(array_filter($attachedIds, static fn (int $id): bool => $id !== $actorId));

        if ($recipientIds === []) {
            return;
        }

        $subject = $this->mentionSubject();
        $subject->subscribers()->syncWithoutDetaching($recipientIds);

        $actor = Auth::user();
        $actor = $actor instanceof User ? $actor : null;

        User::query()->whereIn('id', $recipientIds)->get()
            ->each(static fn (User $user) => $user->notify(new UserMentioned($subject, $actor)));
    }

    /**
     * The subscribable item a mention belongs to: a task/project mentions itself,
     * while a comment mention belongs to the commented-on task or project.
     */
    abstract protected function mentionSubject(): Project|Task;

    /**
     * The rich-text content to scan for mentions. A model carries one of the
     * description/body columns; the other is simply absent.
     */
    protected function mentionContent(): string
    {
        $html = '';

        foreach (['description', 'body'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (filled($value)) {
                $html .= ' '.$value;
            }
        }

        return $html;
    }
}
