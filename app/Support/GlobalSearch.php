<?php

namespace App\Support;

use App\Enums\Status;
use App\Models\Doc;
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
     * Search the user's accessible projects, tasks and reference docs.
     *
     * A query that parses as a reference (e.g. "PROJ-42", the compact "PROJ42",
     * or a doc's "PROJ-D3") yields a pinned "jump to" result at the top. A bare
     * number ("42") surfaces every accessible task with that number,
     * ordered so the current project's task (when $contextShortName is given)
     * comes first. Text/tag matches follow.
     *
     * @param  string|null  $contextShortName  the short_name of the project the user is
     *                                         currently viewing, used to break ties on bare-number matches
     * @return Collection<int, SearchResult>
     */
    public function search(User $user, string $query, ?string $contextShortName = null): Collection
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

        if (ctype_digit($query)) {
            $contextProjectId = $this->contextProjectId($projectIds, $contextShortName);
            $results = $results->merge($this->tasksByNumber($projectIds, (int) $query, $contextProjectId));
        }

        return $results
            ->merge($this->projects($projectIds, $query))
            ->merge($this->tasks($projectIds, $query))
            ->merge($this->docs($user, $projectIds, $query))
            ->unique(static fn (SearchResult $result): string => $result->type.':'.$result->reference)
            ->values();
    }

    /**
     * Resolve a typed reference into a pinned result if the user may view it.
     */
    private function referenceJump(User $user, string $query): ?SearchResult
    {
        $reference = $this->normalizeReference($query);

        $model = ReferenceResolver::doc($reference) ?? ReferenceResolver::commentable($reference);

        if ($model === null || ! $user->can('view', $model)) {
            return null;
        }

        return $this->toResult($model, pinned: true);
    }

    /**
     * Normalize a typed reference so a compact task reference like "PROJ42" (no
     * separator) resolves the same as "PROJ-42". Anything else is returned
     * untouched (uppercased) for the resolver to handle — including a doc
     * reference, which is only ever typed in its "PROJ-D3" form: the compact
     * "PROJD3" is ambiguous (project "PROJD", task 3) and is left as a plain
     * text search rather than guessed at.
     */
    private function normalizeReference(string $query): string
    {
        $query = strtoupper(trim($query));

        return preg_replace('/^('.ReferenceResolver::SHORT_NAME.')-?(\d+)$/', '$1-$2', $query) ?? $query;
    }

    /**
     * The id of the user's current-context project, when given and accessible.
     *
     * @param  array<int, int>  $projectIds
     */
    private function contextProjectId(array $projectIds, ?string $contextShortName): ?int
    {
        if ($contextShortName === null) {
            return null;
        }

        $id = Project::query()
            ->whereIn('id', $projectIds)
            ->where('short_name', strtoupper(trim($contextShortName)))
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Every accessible task carrying the given task number, with the current
     * project's match pinned and ordered first.
     *
     * @param  array<int, int>  $projectIds
     * @return Collection<int, SearchResult>
     */
    private function tasksByNumber(array $projectIds, int $number, ?int $contextProjectId): Collection
    {
        return Task::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->where('task_number', $number)
            ->get()
            ->sortBy(static fn (Task $task): int => $task->project_id === $contextProjectId ? 0 : 1)
            ->take(self::LIMIT)
            ->map(fn (Task $task): SearchResult => $this->toResult($task, pinned: $task->project_id === $contextProjectId))
            ->values();
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

        $terminal = $this->terminalStatusValues();
        $placeholders = implode(', ', array_fill(0, count($terminal), '?'));

        return Task::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where('title', $operator, $like)
                ->orWhereHas('tags', static fn (Builder $tag): Builder => $tag
                    ->where('name', $operator, $like)
                    ->orWhereHas('synonyms', static fn (Builder $synonym): Builder => $synonym->where('name', $operator, $like))))
            // Active tasks first, so completed/canceled matches never crowd open
            // ones out of the limited result set (KAN-327).
            ->orderByRaw("CASE WHEN status IN ($placeholders) THEN 1 ELSE 0 END", $terminal)
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Task $task): SearchResult => $this->toResult($task));
    }

    /**
     * The project's reference docs matching the query by title or tag, most
     * recently updated first.
     *
     * Drafts are visible only to members who may edit that project's docs, so the
     * matches are authorized per doc rather than filtered in SQL: the fetch takes
     * a wider slice and the viewable ones are cut back to the limit.
     *
     * @param  array<int, int>  $projectIds
     * @return Collection<int, SearchResult>
     */
    private function docs(User $user, array $projectIds, string $query): Collection
    {
        $like = $this->like($query);
        $operator = $this->likeOperator();

        return Doc::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where('title', $operator, $like)
                ->orWhereHas('tags', static fn (Builder $tag): Builder => $tag
                    ->where('name', $operator, $like)
                    ->orWhereHas('synonyms', static fn (Builder $synonym): Builder => $synonym->where('name', $operator, $like))))
            ->latest('updated_at')
            ->limit(self::LIMIT * 4)
            ->get()
            ->filter(static fn (Doc $doc): bool => $user->can('view', $doc))
            ->take(self::LIMIT)
            ->map(fn (Doc $doc): SearchResult => $this->toResult($doc))
            ->values();
    }

    /**
     * The stored status values the palette treats as low-priority — terminal
     * states (completed or canceled) that should rank below active tasks.
     *
     * @return list<string>
     */
    private function terminalStatusValues(): array
    {
        return array_values(array_map(
            static fn (Status $status): string => $status->value,
            array_filter(Status::cases(), static fn (Status $status): bool => $status->isTerminal()),
        ));
    }

    /**
     * Map a resolved model into a palette result.
     */
    private function toResult(Project|Task|Doc $model, bool $pinned = false): SearchResult
    {
        return match (true) {
            $model instanceof Doc => new SearchResult(
                type: 'doc',
                title: $model->title,
                icon: 'document-text',
                url: route('doc.show', [
                    'short_name' => $model->project->short_name,
                    'doc_number' => $model->doc_number,
                ]),
                reference: $model->reference,
                pinned: $pinned,
                // A draft is the editor's own work in progress; flag it so it is
                // never mistaken for published project knowledge. The palette
                // renders the wording — this stays a plain marker.
                badge: $model->is_public ? null : 'draft',
            ),
            $model instanceof Task => new SearchResult(
                type: 'task',
                title: $model->title,
                icon: $model->status->icon(),
                url: route('task.show', [
                    'short_name' => $model->project->short_name,
                    'task_number' => $model->task_number,
                ]),
                reference: $model->reference,
                pinned: $pinned,
                // Sink completed/canceled tasks below the actions — but never a
                // deliberate reference jump, which the user typed explicitly.
                deprioritized: ! $pinned && $model->status->isTerminal(),
            ),
            $model instanceof Project => new SearchResult(
                type: 'project',
                title: $model->title,
                icon: 'folder',
                url: route('project.show', ['short_name' => $model->short_name]),
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
