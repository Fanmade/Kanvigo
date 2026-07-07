<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token model, extended with an optional per-project restriction: a
 * token flagged with `restricts_projects` may only see and act on the projects
 * attached via {@see self::projects()}. Unrestricted tokens (the default, and
 * every token created before the feature existed) behave exactly as before.
 *
 * The flag is authoritative — a restricted token whose allowed projects were
 * all deleted has access to nothing, it does not fall back to every project.
 *
 * @property bool $restricts_projects
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Memoised allowed project ids, so per-model authorization checks within a
     * request resolve the set once.
     *
     * @var array<int, int>|null
     */
    protected ?array $allowedProjectIds = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['restricts_projects' => 'boolean'];
    }

    /**
     * The projects a restricted token is allowed to access.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /**
     * Whether this token is restricted to a subset of the owner's projects.
     */
    public function restrictsProjects(): bool
    {
        return (bool) $this->restricts_projects;
    }

    /**
     * Whether this token may access the given project. Unrestricted tokens may
     * access anything the owner may; restricted tokens only their allowed set.
     */
    public function allowsProject(int $projectId): bool
    {
        if (! $this->restrictsProjects()) {
            return true;
        }

        $this->allowedProjectIds ??= $this->projects()->pluck('projects.id')->all();

        return in_array($projectId, $this->allowedProjectIds, true);
    }
}
