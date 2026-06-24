<?php

namespace App\Models;

use App\Authorization\ProjectRoleProvisioner;
use App\Concerns\HasAttachments;
use App\Concerns\HasComments;
use App\Concerns\HasMentions;
use App\Concerns\HasSubscribers;
use App\Concerns\LogsActivity;
use App\Concerns\PrunesInlineAttachments;
use App\Concerns\SanitizesRichText;
use App\Contracts\Mentionable;
use App\Contracts\Subscribable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $short_name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'short_name', 'description'])]
class Project extends Model implements Mentionable, Subscribable
{
    /** @use HasFactory<ProjectFactory> */
    use HasAttachments, HasComments, HasFactory, HasMentions, HasSubscribers, LogsActivity, PrunesInlineAttachments, SanitizesRichText;

    public function inlineAttachmentOwner(): Project|Task
    {
        return $this;
    }

    /**
     * A project's @mentions are limited to its members.
     *
     * @return list<int>
     */
    public function mentionableUserIds(): array
    {
        return array_values(array_map('intval', $this->members()->pluck('users.id')->all()));
    }

    /**
     * A project is its own mention subject.
     */
    protected function mentionSubject(): Project|Task
    {
        return $this;
    }

    public function inlineDocumentColumn(): string
    {
        return 'description';
    }

    public function getRouteKeyName(): string
    {
        return 'short_name';
    }

    /**
     * Derive a suggested short name from a project title.
     *
     * With three or more words, the initials of the first four words are used;
     * otherwise the first three letters of the title. Non-letters are dropped
     * and the result is uppercased. The value only pre-fills the field, so it
     * may fall short of the validated minimum length for very short titles.
     */
    public static function shortNameFromTitle(string $title): string
    {
        $words = preg_split('/\s+/', trim($title), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 3) {
            $candidate = implode('', array_map(
                static fn (string $word): string => mb_substr($word, 0, 1),
                array_slice($words, 0, 4),
            ));
            $limit = 4;
        } else {
            $candidate = $title;
            $limit = 3;
        }

        $letters = preg_replace('/[^a-zA-Z]/', '', $candidate) ?? '';

        return mb_strtoupper(mb_substr($letters, 0, $limit));
    }

    /**
     * Every task in the project, at any nesting depth.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('task_number');
    }

    /**
     * The project's top-level tasks (those without a parent).
     *
     * @return HasMany<Task, $this>
     */
    public function rootTasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereNull('parent_id')->orderBy('task_number');
    }

    /**
     * Notes attached to this project (any owner, any visibility).
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * The tags owned by this project.
     *
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * The users granted access to this project.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * The name of the delegated-permissions role the given user holds on this
     * project, or null if they hold none. A user has at most one project-scoped
     * role, enforced by {@see ProjectRoleProvisioner::syncMember()}.
     */
    public function roleNameFor(User $user): ?string
    {
        return $user->roles()
            ->where('scope_type', $this->getMorphClass())
            ->where('scope_id', $this->getKey())
            ->value('name');
    }

    /**
     * Whether the user owns this project.
     */
    public function isOwner(User $user): bool
    {
        return $this->roleNameFor($user) === 'owner';
    }
}
