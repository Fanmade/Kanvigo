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
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, UserPermission> $permissions
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
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
     * @return MorphToMany<Story, $this>
     */
    public function subscribedStories(): MorphToMany
    {
        return $this->morphedByMany(Story::class, 'subscribable', 'subscriptions')->withTimestamps();
    }

    /**
     * @return MorphToMany<Task, $this>
     */
    public function subscribedTasks(): MorphToMany
    {
        return $this->morphedByMany(Task::class, 'subscribable', 'subscriptions')->withTimestamps();
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
