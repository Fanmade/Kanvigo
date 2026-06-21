<?php

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
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
 * @property-read Collection<int, UserPermission> $permissions
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Detach a user's collaborative relationships when their account is removed,
     * so a soft-deleted account no longer holds project access, task assignments
     * or notification subscriptions. A force delete leaves the database cascades
     * to clean up.
     */
    protected static function booted(): void
    {
        static::deleting(static function (User $user): void {
            if ($user->isForceDeleting()) {
                $user->deleteAvatar();

                return;
            }

            $user->projects()->detach();
            $user->assignedTasks()->detach();
            $user->subscribedProjects()->detach();
            $user->subscribedTasks()->detach();
        });
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
     * Determine whether the user's account is currently deactivated.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Deactivate the account, preventing the user from signing in while keeping
     * their data and assignments intact. The change is reversible via reactivate().
     */
    public function deactivate(): void
    {
        if ($this->isDeactivated()) {
            return;
        }

        $this->forceFill(['deactivated_at' => now()])->save();
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
     * The permissions granted to this user.
     *
     * @return HasMany<UserPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
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
     * Determine whether the user has been granted the given permission.
     */
    public function hasPermission(Permission $permission): bool
    {
        return $this->permissions->contains(
            static fn (UserPermission $userPermission): bool => $userPermission->permission === $permission
        );
    }

    /**
     * Replace the user's granted permissions with the given set.
     *
     * @param  array<int, Permission|string>  $values
     */
    public function syncPermissions(array $values): void
    {
        $permissions = collect($values)
            ->map(static fn (Permission|string $value): Permission => $value instanceof Permission ? $value : Permission::from($value))
            ->unique(static fn (Permission $permission): string => $permission->value);

        $keys = $permissions->map(static fn (Permission $permission): string => $permission->value)->all();

        $this->permissions()->whereNotIn('permission', $keys)->delete();

        $existing = $this->permissions()->pluck('permission')
            ->map(static fn (Permission|string $permission): string => $permission instanceof Permission ? $permission->value : $permission);

        $permissions
            ->reject(static fn (Permission $permission): bool => $existing->contains($permission->value))
            ->each(fn (Permission $permission) => $this->permissions()->create(['permission' => $permission]));

        $this->unsetRelation('permissions');
    }

    /**
     * Scope a query to users that have been granted the given permission.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    #[Scope]
    protected function wherePermission(Builder $query, Permission $permission): Builder
    {
        return $query->whereHas(
            'permissions',
            static fn (Builder $relation): Builder => $relation->where('permission', $permission->value)
        );
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
