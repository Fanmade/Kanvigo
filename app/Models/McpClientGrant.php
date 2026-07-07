<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Passport\Client;

/**
 * A user's OAuth consent for one MCP client connection (e.g. one Claude
 * Desktop connector), optionally restricted to a subset of their projects.
 * The restriction mirrors {@see PersonalAccessToken}: the flag is
 * authoritative, so a restricted grant whose allowed projects were all
 * deleted has access to nothing rather than falling back to every project.
 *
 * @property string $oauth_client_id
 * @property int $user_id
 * @property bool $restricts_projects
 */
#[Fillable(['oauth_client_id', 'user_id', 'restricts_projects'])]
class McpClientGrant extends Model
{
    /**
     * Memoised allowed project ids, so per-request authorization checks
     * resolve the set once.
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
     * The user who granted the connection.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The OAuth client the grant was issued to.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    /**
     * The projects a restricted grant is allowed to access.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /**
     * Whether this grant is restricted to a subset of the user's projects.
     */
    public function restrictsProjects(): bool
    {
        return (bool) $this->restricts_projects;
    }

    /**
     * Whether this grant may access the given project. Unrestricted grants may
     * access anything the user may; restricted grants only their allowed set.
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
