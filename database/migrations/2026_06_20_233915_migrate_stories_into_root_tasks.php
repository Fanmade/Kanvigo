<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-way migration: collapse the Story tier into the unified task tree.
 *
 * Every story becomes a root task; the story's direct tasks become that root's
 * children. Everything that pointed at a story polymorphically (dependencies,
 * tags, attachments, comments, subscriptions, the activity log) and the story's
 * assignees are re-pointed to the new root task. Finally `tasks.story_id` and the
 * `stories` / `story_user` tables are dropped.
 */
return new class extends Migration
{
    private const STORY_TYPE = 'App\Models\Story';

    private const TASK_TYPE = 'App\Models\Task';

    /**
     * Morph tables that may reference a story: table => [type column, id column].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const MORPH_TABLES = [
        'activities' => ['subject_type', 'subject_id'],
        'attachments' => ['attachable_type', 'attachable_id'],
        'comments' => ['commentable_type', 'commentable_id'],
        'subscriptions' => ['subscribable_type', 'subscribable_id'],
        'taggables' => ['taggable_type', 'taggable_id'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            /** @var array<int, int> $storyToRoot story id => new root task id */
            $storyToRoot = [];

            foreach (DB::table('stories')->orderBy('project_id')->orderBy('story_number')->get() as $story) {
                // A temporary number above the project's current max keeps the unique
                // (project_id, task_number) constraint satisfied until the renumber below.
                $maxNumber = (int) DB::table('tasks')->where('project_id', $story->project_id)->max('task_number');
                $maxPosition = (float) DB::table('tasks')->where('project_id', $story->project_id)->max('position');

                $rootId = DB::table('tasks')->insertGetId([
                    'story_id' => $story->id,
                    'project_id' => $story->project_id,
                    'parent_id' => null,
                    'task_number' => $maxNumber + 1,
                    'title' => $story->title,
                    'description' => $story->description,
                    'status' => $this->deriveStatus($story->id),
                    'priority' => $story->priority,
                    'due_date' => $story->due_date,
                    'position' => $maxPosition + 1,
                    'archived_at' => $story->archived_at,
                    'created_at' => $story->created_at,
                    'updated_at' => $story->updated_at,
                ]);

                $storyToRoot[$story->id] = $rootId;
            }

            foreach ($storyToRoot as $storyId => $rootId) {
                // The story's direct tasks become children of the new root (the root
                // itself is excluded). Already-nested tasks keep their parent.
                DB::table('tasks')
                    ->where('story_id', $storyId)
                    ->whereNull('parent_id')
                    ->where('id', '!=', $rootId)
                    ->update(['parent_id' => $rootId]);

                $this->repointMorphs($storyId, $rootId);
                $this->repointDependencies($storyId, $rootId);
                $this->moveAssignees($storyId, $rootId);
            }

            $this->dropSelfDependencies();
            $this->renumberTasksPerProject();
        });

        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropForeign(['story_id']);
            $table->dropIndex(['story_id', 'status']);
            $table->dropColumn('story_id');
        });

        Schema::dropIfExists('story_user');
        Schema::dropIfExists('stories');
    }

    public function down(): void
    {
        throw new RuntimeException('Collapsing stories into tasks is a one-way migration and cannot be reversed.');
    }

    /**
     * A sensible status for the new root task, derived from the story's direct
     * tasks: Done when every non-canceled child is done, In progress when any
     * child has started, otherwise Planned.
     */
    private function deriveStatus(int $storyId): string
    {
        /** @var array<int, string> $statuses */
        $statuses = DB::table('tasks')
            ->where('story_id', $storyId)
            ->whereNull('parent_id')
            ->pluck('status')
            ->all();

        if ($statuses === []) {
            return 'Planned';
        }

        $active = array_values(array_filter($statuses, static fn (string $status): bool => $status !== 'Canceled'));

        if ($active !== [] && array_filter($active, static fn (string $status): bool => $status !== 'Done') === []) {
            return 'Done';
        }

        if (in_array('In progress', $statuses, true) || in_array('Done', $statuses, true)) {
            return 'In progress';
        }

        return 'Planned';
    }

    private function repointMorphs(int $storyId, int $rootId): void
    {
        foreach (self::MORPH_TABLES as $table => [$typeColumn, $idColumn]) {
            DB::table($table)
                ->where($typeColumn, self::STORY_TYPE)
                ->where($idColumn, $storyId)
                ->update([$typeColumn => self::TASK_TYPE, $idColumn => $rootId]);
        }
    }

    private function repointDependencies(int $storyId, int $rootId): void
    {
        DB::table('dependencies')
            ->where('dependent_type', self::STORY_TYPE)
            ->where('dependent_id', $storyId)
            ->update(['dependent_type' => self::TASK_TYPE, 'dependent_id' => $rootId]);

        DB::table('dependencies')
            ->where('blocker_type', self::STORY_TYPE)
            ->where('blocker_id', $storyId)
            ->update(['blocker_type' => self::TASK_TYPE, 'blocker_id' => $rootId]);
    }

    /**
     * Move a story's assignees onto its root task, skipping any already assigned.
     */
    private function moveAssignees(int $storyId, int $rootId): void
    {
        foreach (DB::table('story_user')->where('story_id', $storyId)->get() as $row) {
            $exists = DB::table('task_user')
                ->where('task_id', $rootId)
                ->where('user_id', $row->user_id)
                ->exists();

            if (! $exists) {
                DB::table('task_user')->insert([
                    'task_id' => $rootId,
                    'user_id' => $row->user_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        }
    }

    /**
     * Re-pointing can collapse a story→its-own-task dependency into a self-link.
     * Drop any dependency whose two ends are now the same task.
     */
    private function dropSelfDependencies(): void
    {
        DB::table('dependencies')
            ->whereColumn('dependent_type', 'blocker_type')
            ->whereColumn('dependent_id', 'blocker_id')
            ->delete();
    }

    /**
     * Give every task a contiguous per-project number. Offsetting first avoids a
     * transient collision on the unique (project_id, task_number) constraint.
     */
    private function renumberTasksPerProject(): void
    {
        foreach (DB::table('tasks')->distinct()->pluck('project_id') as $projectId) {
            DB::table('tasks')->where('project_id', $projectId)->update([
                'task_number' => DB::raw('task_number + 1000000'),
            ]);

            $number = 0;

            foreach (DB::table('tasks')->where('project_id', $projectId)->orderBy('id')->pluck('id') as $id) {
                DB::table('tasks')->where('id', $id)->update(['task_number' => ++$number]);
            }
        }
    }
};
