<?php

namespace App\Models;

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
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property string $title
 * @property string $short_name
 * @property string|null $description
 * @property int|null $auto_archive_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'short_name', 'description', 'auto_archive_days'])]
class Project extends Model implements Mentionable, Subscribable
{
    /** @use HasFactory<ProjectFactory> */
    use HasAttachments, HasComments, HasFactory, HasMentions, HasSubscribers, LogsActivity, PrunesInlineAttachments, SanitizesRichText;

    /**
     * Display precedence of the base project roles (lower wins). Custom roles
     * have no entry and rank after the base roles, alphabetically.
     *
     * @var array<string, int>
     */
    private const array ROLE_RANK = ['owner' => 0, 'admin' => 1, 'member' => 2, 'viewer' => 3];

    /**
     * Short names reserved for routing/subdomains, never assignable to a project.
     *
     * @var list<string>
     */
    public const array RESERVED_SHORT_NAMES = ['WWW', 'API', 'APP', 'FTP'];

    /**
     * Validation rules for a project short_name (2-4 uppercase letters, not a
     * reserved name, unique). Pass the id of the project being edited to exempt
     * it from the uniqueness check. Callers normalize the input to uppercase
     * first and may prepend `sometimes` for partial updates.
     *
     * @return list<mixed>
     */
    public static function shortNameRules(?int $ignoreId = null): array
    {
        return [
            'required', 'string', 'min:2', 'max:4', 'alpha', 'uppercase',
            Rule::notIn(self::RESERVED_SHORT_NAMES),
            $ignoreId === null
                ? Rule::unique('projects', 'short_name')
                : Rule::unique('projects', 'short_name')->ignore($ignoreId),
        ];
    }

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_archive_days' => 'integer',
        ];
    }

    /**
     * The effective number of days a Done task may sit before it is auto-archived
     * in this project, or null when auto-archiving is disabled. A per-project
     * value overrides the global default; an explicit 0 disables it here.
     */
    public function autoArchiveThresholdDays(): ?int
    {
        $days = $this->auto_archive_days ?? (int) config('kanvigo.tasks.auto_archive_days', 0);

        return $days > 0 ? $days : null;
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
     * The task types configured for this project.
     *
     * @return HasMany<TaskType, $this>
     */
    public function taskTypes(): HasMany
    {
        return $this->hasMany(TaskType::class)->orderBy('position');
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
     * The names of every delegated-permissions role the given user holds on this
     * project (a user may hold several — e.g. Designer + Reviewer). Empty when
     * they hold none. Ordered highest-first by {@see self::ROLE_RANK}.
     *
     * @return list<string>
     */
    public function roleNamesFor(User $user): array
    {
        // Reuse an already eager-loaded `roles` relation (the member panel loads
        // it in bulk) instead of issuing a fresh query per call; only fall back
        // to a scoped query when the relation isn't loaded.
        $roles = $user->relationLoaded('roles')
            ? $user->roles
                ->where('scope_type', $this->getMorphClass())
                ->where('scope_id', $this->getKey())
            : $user->roles()
                ->where('scope_type', $this->getMorphClass())
                ->where('scope_id', $this->getKey())
                ->get();

        $names = $roles
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->sortBy(static fn (string $name): string => sprintf('%d-%s', self::ROLE_RANK[$name] ?? 9, $name))
            ->all();

        return array_values($names);
    }

    /**
     * The name of the user's highest-ranked role on this project, or null if they
     * hold none — the role to show when a single label is needed.
     */
    public function roleNameFor(User $user): ?string
    {
        return $this->roleNamesFor($user)[0] ?? null;
    }

    /**
     * Whether the user owns this project.
     */
    public function isOwner(User $user): bool
    {
        return in_array('owner', $this->roleNamesFor($user), true);
    }
}
