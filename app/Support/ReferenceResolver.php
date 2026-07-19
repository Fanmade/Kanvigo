<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;

/**
 * Resolves public references (e.g. "PROJ" or "PROJ-42") into models, mirroring
 * the URL resolution rules used by the scoped web routes in routes/web.php.
 */
class ReferenceResolver
{
    /**
     * The short name pattern: 2-4 uppercase letters, matching the web routes.
     */
    public const string SHORT_NAME = '[A-Z]{2,4}';

    /**
     * Resolve a task reference (e.g. "PROJ-42") into its model.
     *
     * Returns null when the reference is malformed or no matching task exists.
     */
    public static function task(string $reference): ?Task
    {
        if (! preg_match('/^('.self::SHORT_NAME.')-(\d+)$/', strtoupper(trim($reference)), $matches)) {
            return null;
        }

        [, $shortName, $taskNumber] = $matches;

        $project = Project::query()->where('short_name', $shortName)->first();

        if ($project === null) {
            return null;
        }

        return Task::query()
            ->with(['assignees', 'project'])
            ->where('project_id', $project->id)
            ->where('task_number', (int) $taskNumber)
            ->first();
    }

    /**
     * Resolve an activity-log reference (e.g. "PROJ-42-log-2") into its entry —
     * the 2nd activity recorded on task PROJ-42. The "-log-" infix keeps it from
     * colliding with the task ("PROJ-42") and project ("PROJ") reference forms.
     *
     * Returns null when the reference is malformed or no matching entry exists.
     */
    public static function activity(string $reference): ?Activity
    {
        if (! preg_match('/^('.self::SHORT_NAME.')-(\d+)-LOG-(\d+)$/', strtoupper(trim($reference)), $matches)) {
            return null;
        }

        [, $shortName, $taskNumber, $sequence] = $matches;

        $task = self::task($shortName.'-'.$taskNumber);

        if ($task === null) {
            return null;
        }

        return Activity::query()
            ->where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->id)
            ->where('sequence', (int) $sequence)
            ->first();
    }

    /**
     * Resolve a project reference (its short_name, e.g. "PROJ") into its model.
     *
     * Returns null when the reference is malformed or no matching project exists.
     */
    public static function project(string $reference): ?Project
    {
        $shortName = strtoupper(trim($reference));

        if (! preg_match('/^'.self::SHORT_NAME.'$/', $shortName)) {
            return null;
        }

        return Project::query()->where('short_name', $shortName)->first();
    }

    /**
     * Resolve a doc reference (e.g. "PROJ-D3") into its model. The "-D" infix
     * keeps it from colliding with the task ("PROJ-3") and project ("PROJ")
     * reference forms.
     *
     * Returns null when the reference is malformed or no matching doc exists.
     */
    public static function doc(string $reference): ?Doc
    {
        if (! preg_match('/^('.self::SHORT_NAME.')-D(\d+)$/', strtoupper(trim($reference)), $matches)) {
            return null;
        }

        [, $shortName, $docNumber] = $matches;

        $project = Project::query()->where('short_name', $shortName)->first();

        if ($project === null) {
            return null;
        }

        return Doc::query()
            ->with('project')
            ->where('project_id', $project->id)
            ->where('doc_number', (int) $docNumber)
            ->first();
    }

    /**
     * Resolve any commentable reference into its model: a task ("PROJ-42")
     * or a project ("PROJ").
     *
     * Returns null when the reference is malformed or no matching model exists.
     */
    public static function commentable(string $reference): Project|Task|null
    {
        $reference = strtoupper(trim($reference));

        if (str_contains($reference, '-')) {
            return self::task($reference);
        }

        return self::project($reference);
    }
}
