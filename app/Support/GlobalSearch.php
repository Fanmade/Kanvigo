<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Story;
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
     * Search the user's accessible projects, stories and tasks.
     *
     * A query that parses as a reference (e.g. "PROJ1-3") yields a pinned
     * "jump to" result at the top, followed by text/keyword matches.
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
            ->merge($this->stories($projectIds, $query))
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

        return Project::query()
            ->whereIn('id', $projectIds)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where('title', 'like', $like)
                ->orWhere('short_name', 'like', $like))
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Project $project): SearchResult => $this->toResult($project));
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return Collection<int, SearchResult>
     */
    private function stories(array $projectIds, string $query): Collection
    {
        $like = $this->like($query);

        return Story::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where('title', 'like', $like)
                ->orWhereHas('keywords', static fn (Builder $keyword): Builder => $keyword->where('name', 'like', $like)))
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Story $story): SearchResult => $this->toResult($story));
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return Collection<int, SearchResult>
     */
    private function tasks(array $projectIds, string $query): Collection
    {
        $like = $this->like($query);

        return Task::query()
            ->with('story.project')
            ->whereHas('story', static fn (Builder $story): Builder => $story->whereIn('project_id', $projectIds))
            ->where(static fn (Builder $builder): Builder => $builder
                ->where('title', 'like', $like)
                ->orWhereHas('keywords', static fn (Builder $keyword): Builder => $keyword->where('name', 'like', $like)))
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Task $task): SearchResult => $this->toResult($task));
    }

    /**
     * Map a resolved model into a palette result.
     */
    private function toResult(Project|Story|Task $model, bool $pinned = false): SearchResult
    {
        return match (true) {
            $model instanceof Task => new SearchResult(
                type: 'task',
                title: $model->title,
                url: route('task.show', [
                    'short_name' => $model->story->project->short_name,
                    'story_number' => $model->story->story_number,
                    'task_number' => $model->task_number,
                ]),
                icon: $model->status->icon(),
                reference: $model->reference,
                pinned: $pinned,
            ),
            $model instanceof Story => new SearchResult(
                type: 'story',
                title: $model->title,
                url: route('story.show', [
                    'short_name' => $model->project->short_name,
                    'story_number' => $model->story_number,
                ]),
                icon: 'rectangle-stack',
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
}
