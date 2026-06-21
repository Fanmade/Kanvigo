<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single entry point for command palette matching. Encapsulates every query
 * so the backend (plain Eloquent today) can later swap to a search engine
 * without touching the palette component or any other call site.
 */
class GlobalSearch
{
    /**
     * Maximum matches returned per entity type.
     */
    private const LIMIT = 5;

    /**
     * The ids of every project the user may access.
     *
     * @return array<int, int>
     */
    public function accessibleProjectIds(User $user): array
    {
        return $user->projects()->pluck('projects.id')->all();
    }

    /**
     * Search the user's accessible projects and tasks.
     *
     * A query that parses as a reference (e.g. "PROJ-42") yields a pinned
     * "jump to" result at the top, followed by text/tag matches.
     *
     * @return Collection<int, SearchResult>
     */
    public function search(User $user, string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        /** @var Collection<int, SearchResult> $results */
        $results = collect();

        if (($pinned = $this->referenceJump($user, $query)) !== null) {
            $results->push($pinned);
        }

        $projectIds = $this->accessibleProjectIds($user);

        if ($projectIds === []) {
            return $results->values();
        }

        return $results
            ->merge($this->projects($projectIds, $query))
            ->merge($this->tasks($projectIds, $query))
            ->unique(static fn (SearchResult $result): string => $result->type.':'.$result->reference)
            ->values();
    }

    /**
     * Resolve a typed reference into a pinned result if the user may view it.
     */
    private function referenceJump(User $user, string $query): ?SearchResult
    {
        $model = ReferenceResolver::commentable($query);

        if ($model === null || ! $user->can('view', $model)) {
            return null;
        }

        return $this->toResult($model, pinned: true);
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return Collection<int, SearchResult>
     */
    private function projects(array $projectIds, string $query): Collection
    {
        $like = $this->like($query);
        $operator = $this->likeOperator();

        return Project::query()
            ->whereIn('id', $projectIds)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where('title', $operator, $like)
                ->orWhere('short_name', $operator, $like))
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Project $project): SearchResult => $this->toResult($project));
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return Collection<int, SearchResult>
     */
    private function tasks(array $projectIds, string $query): Collection
    {
        $like = $this->like($query);
        $operator = $this->likeOperator();

        return Task::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where('title', $operator, $like)
                ->orWhereHas('tags', static fn (Builder $tag): Builder => $tag->where('name', $operator, $like)))
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Task $task): SearchResult => $this->toResult($task));
    }

    /**
     * Map a resolved model into a palette result.
     */
    private function toResult(Project|Task $model, bool $pinned = false): SearchResult
    {
        return match (true) {
            $model instanceof Task => new SearchResult(
                type: 'task',
                title: $model->title,
                url: route('task.show', [
                    'short_name' => $model->project->short_name,
                    'task_number' => $model->task_number,
                ]),
                icon: $model->status->icon(),
                reference: $model->reference,
                pinned: $pinned,
            ),
            $model instanceof Project => new SearchResult(
                type: 'project',
                title: $model->title,
                url: route('project.show', ['short_name' => $model->short_name]),
                icon: 'folder',
                reference: $model->short_name,
                pinned: $pinned,
            ),
        };
    }

    /**
     * Build an escaped LIKE pattern for a case-insensitive "contains" match.
     */
    private function like(string $query): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
    }

    /**
     * The case-insensitive LIKE operator for the active connection.
     */
    private function likeOperator(): string
    {
        return self::likeOperatorFor((new Project)->getConnection()->getDriverName());
    }

    /**
     * Map a connection driver to a case-insensitive LIKE operator.
     *
     * `ilike` is a PostgreSQL-only extension, not standard SQL. PostgreSQL is also
     * the odd one out in treating `like` as case-sensitive under its default
     * collation, so it needs `ilike`. Everywhere else plain `like` already folds
     * case — SQLite for ASCII, MySQL/MariaDB/SQL Server via their default
     * case-insensitive collations — and none of them understand the `ilike`
     * keyword. Without this the palette silently misses matches whose case differs
     * from the stored title on production PostgreSQL.
     */
    public static function likeOperatorFor(string $driver): string
    {
        return $driver === 'pgsql' ? 'ilike' : 'like';
    }
}
