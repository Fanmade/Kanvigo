<?php

namespace App\Queries;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The recent activity shown on a user's profile page.
 *
 * Returns the activities recorded by the profile's owner, newest first, but only
 * those whose subject lives in a project the viewer is allowed to see — so a
 * profile never leaks what its owner did in projects the viewer has no access to.
 * Subjects (and a task subject's project) are eager-loaded for display.
 */
class UserProfileActivity
{
    /**
     * The number of entries to surface. Bounds the load instead of pulling the
     * user's entire history.
     */
    public function __construct(private readonly int $limit = 15) {}

    /**
     * @return Collection<int, Activity>
     */
    public function handle(User $owner, User $viewer): Collection
    {
        // Null means "every project" — the viewer can access all of them.
        $projectIds = $viewer->canAccessAllProjects()
            ? null
            : $viewer->projects()->pluck('projects.id');

        return Activity::query()
            ->where('user_id', $owner->getKey())
            ->where(static function (Builder $query) use ($projectIds): void {
                $query
                    ->where(static function (Builder $tasks) use ($projectIds): void {
                        $tasks
                            ->where('subject_type', (new Task)->getMorphClass())
                            ->whereIn('subject_id', Task::query()
                                ->when($projectIds !== null, static fn (Builder $task): Builder => $task->whereIn('project_id', $projectIds))
                                ->select('id'));
                    })
                    ->orWhere(static function (Builder $projects) use ($projectIds): void {
                        $projects->where('subject_type', (new Project)->getMorphClass());

                        if ($projectIds !== null) {
                            $projects->whereIn('subject_id', $projectIds);
                        }
                    });
            })
            ->with(['subject' => static fn ($morphTo) => $morphTo->morphWith([
                Task::class => ['project'],
                Project::class => [],
            ])])
            ->latest()
            ->limit($this->limit)
            ->get();
    }
}
