<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make tag names unique per project case-insensitively, so "Bug" and "bug"
     * can no longer coexist in one project. First merge any existing
     * case-variant duplicates, then swap the exact (project_id, name) unique for
     * a functional (project_id, lower(name)) one — supported by both SQLite and
     * PostgreSQL.
     */
    public function up(): void
    {
        $this->mergeCaseVariantDuplicates();

        Schema::table('tags', static function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'name']);
        });

        DB::statement('CREATE UNIQUE INDEX tags_project_id_lower_name_unique ON tags (project_id, lower(name))');
    }

    /**
     * Within each project, collapse tags whose names differ only by case into a
     * single tag — the earliest-created (lowest id) wins — repointing the pivot
     * rows and dropping the now-redundant duplicates.
     */
    private function mergeCaseVariantDuplicates(): void
    {
        $groups = DB::table('tags')->orderBy('id')->get()
            ->groupBy(static fn (object $tag): string => $tag->project_id.'|'.mb_strtolower($tag->name));

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $canonicalId = $group->first()->id;

            foreach ($group->slice(1) as $duplicate) {
                $this->mergeTagInto($duplicate->id, $canonicalId);
                DB::table('tags')->where('id', $duplicate->id)->delete();
            }
        }
    }

    /**
     * Move a duplicate tag's pivot rows onto the canonical tag, dropping any that
     * would collide with a row the canonical tag already has.
     */
    private function mergeTagInto(int $duplicateId, int $canonicalId): void
    {
        foreach (DB::table('taggables')->where('tag_id', $duplicateId)->get() as $row) {
            $pivot = DB::table('taggables')
                ->where('tag_id', $duplicateId)
                ->where('taggable_id', $row->taggable_id)
                ->where('taggable_type', $row->taggable_type);

            $alreadyOnCanonical = DB::table('taggables')
                ->where('tag_id', $canonicalId)
                ->where('taggable_id', $row->taggable_id)
                ->where('taggable_type', $row->taggable_type)
                ->exists();

            $alreadyOnCanonical ? $pivot->delete() : $pivot->update(['tag_id' => $canonicalId]);
        }
    }

    /**
     * Reverse the migrations. The merged duplicates cannot be recovered; this
     * only restores the exact-match unique index.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX tags_project_id_lower_name_unique');

        Schema::table('tags', static function (Blueprint $table): void {
            $table->unique(['project_id', 'name']);
        });
    }
};
