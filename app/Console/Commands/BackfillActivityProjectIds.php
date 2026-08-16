<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\Variable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills in `activities.project_id` for rows written before the column existed.
 * Safe to re-run and to interrupt: only rows still missing a project are
 * touched, in chunks, so a large table doesn't have to be held in memory or
 * updated in one transaction.
 *
 * Rows whose subject no longer exists cannot be resolved — nothing records
 * which project a deleted task belonged to. They are reported, not guessed at.
 */
class BackfillActivityProjectIds extends Command
{
    protected $signature = 'activities:backfill-projects {--chunk=1000 : How many rows to resolve per pass}';

    protected $description = 'Fill in the owning project on activity rows recorded before the column existed';

    /**
     * The activity subjects, mapped to the table and column their project comes
     * from. A project answers with its own id.
     *
     * @var array<class-string<Model>, array{table: string, column: string}>
     */
    private const array SUBJECT_SOURCES = [
        Task::class => ['table' => 'tasks', 'column' => 'project_id'],
        Doc::class => ['table' => 'docs', 'column' => 'project_id'],
        Variable::class => ['table' => 'variables', 'column' => 'project_id'],
        Project::class => ['table' => 'projects', 'column' => 'id'],
    ];

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $filled = 0;

        foreach (self::SUBJECT_SOURCES as $subjectType => $source) {
            $filled += $this->backfillSubjectType($subjectType, $source['table'], $source['column'], $chunk);
        }

        $this->info("Filled the project on {$filled} activity row(s).");

        $orphans = Activity::query()->whereNull('project_id')->count();

        if ($orphans > 0) {
            $this->warn("{$orphans} row(s) still have no project — their subject no longer exists.");
        }

        return self::SUCCESS;
    }

    /**
     * Resolve one subject type, a chunk of rows at a time. Each pass reads the
     * ids it can resolve and writes them back grouped by project, so a chunk
     * costs one select plus one update per distinct project rather than one
     * update per row.
     */
    private function backfillSubjectType(string $subjectType, string $table, string $column, int $chunk): int
    {
        $filled = 0;

        while (true) {
            // Both tables carry an "id", so the two columns are aliased apart —
            // without that the join's duplicate names collapse and the mapping
            // silently pairs the wrong ids.
            $resolved = DB::table('activities')
                ->join($table, $table.'.id', '=', 'activities.subject_id')
                ->where('activities.subject_type', $subjectType)
                ->whereNull('activities.project_id')
                ->limit($chunk)
                ->select([
                    'activities.id as activity_id',
                    $table.'.'.$column.' as owning_project_id',
                ])
                ->pluck('owning_project_id', 'activity_id');

            if ($resolved->isEmpty()) {
                return $filled;
            }

            $byProject = $this->groupByProject($resolved);

            // Nothing resolvable in this chunk (a subject row without a project
            // at all) — stop rather than re-reading the same rows forever.
            if ($byProject === []) {
                return $filled;
            }

            foreach ($byProject as $projectId => $activityIds) {
                $filled += DB::table('activities')
                    ->whereIn('id', $activityIds)
                    ->update(['project_id' => $projectId]);
            }
        }
    }

    /**
     * Invert an "activity id => project id" map into "project id => activity ids".
     *
     * @param  Collection<int, int|string|null>  $resolved
     * @return array<int, list<int>>
     */
    private function groupByProject(Collection $resolved): array
    {
        $byProject = [];

        foreach ($resolved as $activityId => $projectId) {
            if ($projectId !== null) {
                $byProject[(int) $projectId][] = (int) $activityId;
            }
        }

        return $byProject;
    }
}
