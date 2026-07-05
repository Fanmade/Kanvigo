<?php

namespace App\Models;

use App\Authorization\AccountPermissionProvisioner;
use App\Enums\Permission;
use App\Support\Facades\Audit;
use Database\Factories\UserFactory;
use Fanmade\DelegatedPermissions\Concerns\HasRoles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvigo\Audit\Contracts\AuditCategory;
use Kanvigo\Audit\Contracts\AuditEvent;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property string|null $avatar_path
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, mixed>|null $preferences
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    // The package's per-project permission check, aliased so it doesn't collide
    // with the account-level hasPermission(Permission) above.
    use HasRoles {
        hasPermission as hasScopedPermission;
    }

    /**
     * Detach a user's collaborative relationships when their account is removed,
     * so a soft-deleted account no longer holds project access, task assignments
     * or notification subscriptions. A force delete leaves the database cascades
     * to clean up.
     */
    protected static function booted(): void
    {
        // Every user gets an opaque public identifier on creation; it is the
        // route key, so profile and avatar URLs never expose the numeric id.
        static::creating(static function (User $user): void {
            $user->public_id ??= (string) Str::ulid();
        });

        // Registration is invitation-only, so account creation happens outside
        // Fortify — audit it at the model level to cover every path.
        static::created(static function (User $user): void {
            Audit::record($user->securityAuditEvent('registered', AuditCategory::Authn));
        });

        static::deleting(static function (User $user): void {
            Audit::record($user->securityAuditEvent('user_deleted')
                ->withMetadata(['force' => $user->isForceDeleting()]));

            if ($user->isForceDeleting()) {
                $user->deleteAvatar();

                return;
            }

            $user->projects()->detach();
            $user->roles()->detach();
            $user->assignedTasks()->detach();
            $user->subscribedProjects()->detach();
            $user->subscribedTasks()->detach();
        });
    }

    /**
     * An account-lifecycle audit event with this user as its subject.
     */
    public function securityAuditEvent(string $action, AuditCategory $category = AuditCategory::Security): AuditEvent
    {
        return AuditEvent::make($action, $category)
            ->withSubject($this->getMorphClass(), $this->getKey());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /**
     * Resolve route-model bindings by the opaque public id rather than the
     * primary key, keeping the sequential id out of profile and avatar URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Determine whether the user's account is currently deactivated.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Deactivate the account, preventing the user from signing in while keeping
     * their data and assignments intact. The change is reversible via reactivate().
     * Existing personal access tokens are revoked, so REST/MCP access stops at
     * once; the user must be issued new tokens after a reactivation.
     */
    public function deactivate(): void
    {
        if ($this->isDeactivated()) {
            return;
        }

        $this->forceFill(['deactivated_at' => now()])->save();

        foreach ($this->tokens()->pluck('name', 'id') as $tokenId => $tokenName) {
            Audit::record(AuditEvent::make('token_revoked', AuditCategory::Token)
                ->withSubject($this->getMorphClass(), $this->getKey())
                ->withMetadata(['token_id' => $tokenId, 'token' => $tokenName, 'reason' => 'deactivated']));
        }

        $this->tokens()->delete();

        Audit::record($this->securityAuditEvent('user_deactivated'));
    }

    /**
     * Restore a previously deactivated account.
     */
    public function reactivate(): void
    {
        if (! $this->isDeactivated()) {
            return;
        }

        $this->forceFill(['deactivated_at' => null])->save();

        Audit::record($this->securityAuditEvent('user_reactivated'));
    }

    /**
     * Read a single UI preference value, falling back to the given default.
     */
    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }

    /**
     * Persist a single UI preference value.
     */
    public function setPreference(string $key, mixed $value): void
    {
        $preferences = $this->preferences ?? [];
        data_set($preferences, $key, $value);
        $this->preferences = $preferences;
        $this->save();
    }

    /**
     * The projects this user has been granted access to.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /**
     * Determine whether this user shares at least one project with another user.
     * Used to govern who may see a user's contact details: project collaborators
     * already see each other's names on tasks and comments, so exposing their
     * email to one another is reasonable, while strangers are kept apart.
     */
    public function sharesProjectWith(User $other): bool
    {
        if ($this->id === $other->id) {
            return true;
        }

        return $this->projects()
            ->whereIn('projects.id', $other->projects()->select('projects.id'))
            ->exists();
    }

    /**
     * The invitations this user has sent.
     *
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    /**
     * The invitations this user has sent that are still pending — unaccepted and
     * unexpired. Mirrors the conditions of Invitation::scopeValid().
     *
     * @return HasMany<Invitation, $this>
     */
    public function pendingInvitations(): HasMany
    {
        return $this->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Determine whether the user has been granted the given account permission.
     *
     * Account permissions resolve entirely from the package's global scope: the
     * user holds the permission's global role (or the system role, which holds
     * every permission as break-glass). See {@see AccountPermissionProvisioner}.
     */
    public function hasPermission(Permission $permission): bool
    {
        return $this->hasScopedPermission($permission->value);
    }

    /**
     * Whether the user may see every project — the account-level
     * access-all-projects grant. A cross-project read/visibility grant: it opens
     * the project list and lets the policy's view check pass, but confers no
     * scoped ability to contribute or administer a project.
     */
    public function canAccessAllProjects(): bool
    {
        return $this->hasPermission(Permission::AccessAllProjects);
    }

    /**
     * Replace the user's account permissions with the given set, assigning the
     * matching global roles on the package and removing the rest.
     *
     * @param  array<int, Permission|string>  $values
     */
    public function syncPermissions(array $values): void
    {
        $permissions = collect($values)
            ->map(static fn (Permission|string $value): Permission => $value instanceof Permission ? $value : Permission::from($value))
            ->all();

        app(AccountPermissionProvisioner::class)->sync($this, $permissions);
    }

    /**
     * Scope a query to users that hold the given account permission's global
     * role. (System-role break-glass holders are not matched here — this targets
     * an explicit grant.)
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    #[Scope]
    protected function wherePermission(Builder $query, Permission $permission): Builder
    {
        $role = app(AccountPermissionProvisioner::class)->provision()[$permission->value];

        return $query->whereHas('roles', static fn (Builder $relation): Builder => $relation->whereKey($role->getKey()));
    }

    /**
     * The tasks assigned to this user.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)->withTimestamps();
    }

    /**
     * The activities recorded by this user.
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * The number of unread notifications, read on every authenticated page for
     * the nav badge. Cached per user so it is a cheap cache hit instead of a
     * `count(*)` each request; {@see forgetUnreadNotificationCount()} busts it
     * whenever the user's notifications change (created, read, or deleted).
     */
    public function unreadNotificationCount(): int
    {
        return Cache::rememberForever(
            self::unreadNotificationCacheKey($this->getKey()),
            fn (): int => $this->unreadNotifications()->count(),
        );
    }

    /**
     * Drop the cached unread-notification count for a user, forcing a recount on
     * the next read. Called from the notification model's lifecycle events.
     */
    public static function forgetUnreadNotificationCount(int|string $userId): void
    {
        Cache::forget(self::unreadNotificationCacheKey($userId));
    }

    /**
     * The cache key holding a user's unread-notification count.
     */
    private static function unreadNotificationCacheKey(int|string $userId): string
    {
        return 'notifications:unread:'.$userId;
    }

    /**
     * The notes owned by this user.
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return MorphToMany<Project, $this>
     */
    public function subscribedProjects(): MorphToMany
    {
        return $this->morphedByMany(Project::class, 'subscribable', 'subscriptions')->withTimestamps();
    }

    /**
     * @return MorphToMany<Task, $this>
     */
    public function subscribedTasks(): MorphToMany
    {
        return $this->morphedByMany(Task::class, 'subscribable', 'subscriptions')->withTimestamps();
    }

    /**
     * The private storage disk that holds user avatar images. Avatars are not
     * publicly accessible; they are streamed to authenticated viewers through
     * the named "avatar" route instead.
     */
    public const string AVATAR_DISK = 'local';

    /**
     * Determine whether the user has uploaded an avatar image.
     */
    public function hasAvatar(): bool
    {
        return $this->avatar_path !== null;
    }

    /**
     * The URL of the user's avatar, served through the authorized avatar route,
     * or null when they have none and their initials should be shown as the
     * fallback instead. The cache-busting query updates when the image changes.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        return route('avatar', ['user' => $this, 'v' => $this->updated_at?->timestamp]);
    }

    /**
     * Remove the user's stored avatar file and clear the reference to it.
     */
    public function deleteAvatar(): void
    {
        if ($this->avatar_path === null) {
            return;
        }

        Storage::disk(self::AVATAR_DISK)->delete($this->avatar_path);

        $this->avatar_path = null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(static fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
