<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move task numbering from a per-story sequence (reference "ABC1-3") to a flat
     * per-project sequence (reference "ABC-42"). The hierarchy now lives entirely in
     * `parent_id`, not in the reference, so tasks carry their own `project_id` and the
     * uniqueness of the number is enforced per project rather than per story.
     */
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->foreignId('project_id')
                ->nullable()
                ->after('story_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->index('project_id');
        });

        // Backfill each task's project from its story (works on SQLite and Postgres).
        DB::statement('UPDATE tasks SET project_id = (SELECT project_id FROM stories WHERE stories.id = tasks.story_id)');

        // Drop the per-story unique BEFORE renumbering: otherwise assigning a
        // project-wide number can transiently collide with a not-yet-renumbered
        // task that still holds that number within the same story.
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropUnique(['story_id', 'task_number']);
        });

        $this->renumberTasksPerProject();

        Schema::table('tasks', static function (Blueprint $table): void {
            $table->unique(['project_id', 'task_number']);
            $table->foreignId('project_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse to per-story numbering.
     */
    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'task_number']);
        });

        $this->renumberTasksPerStory();

        Schema::table('tasks', static function (Blueprint $table): void {
            $table->unique(['story_id', 'task_number']);
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }

    /**
     * Assign each project's tasks a contiguous 1..N number, ordered by id so the
     * sequence is deterministic and the new unique constraint can be satisfied.
     */
    private function renumberTasksPerProject(): void
    {
        foreach (DB::table('tasks')->distinct()->pluck('project_id') as $projectId) {
            $number = 0;

            foreach (DB::table('tasks')->where('project_id', $projectId)->orderBy('id')->pluck('id') as $id) {
                DB::table('tasks')->where('id', $id)->update(['task_number' => ++$number]);
            }
        }
    }

    /**
     * Restore per-story 1..N numbering when rolling back.
     */
    private function renumberTasksPerStory(): void
    {
        foreach (DB::table('tasks')->distinct()->pluck('story_id') as $storyId) {
            $number = 0;

            foreach (DB::table('tasks')->where('story_id', $storyId)->orderBy('id')->pluck('id') as $id) {
                DB::table('tasks')->where('id', $id)->update(['task_number' => ++$number]);
            }
        }
    }
};
