<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carries the owning project on every activity row.
     *
     * Every activity subject belongs to a project — directly (a task, doc or
     * variable) or as the project itself — but reaching it means a polymorphic
     * join per subject type. A cross-project feed has to filter by project on
     * every read to authorize it, so the id is denormalized onto the row and
     * indexed alongside the sort column.
     *
     * Existing rows are filled by `activities:backfill-projects`. The column
     * stays nullable: an activity whose subject was hard-deleted has no project
     * left to name, and losing those rows would cost more than the constraint
     * buys. Nothing hides behind a null — the feed authorizes by project, so a
     * row without one is visible to no one.
     */
    public function up(): void
    {
        Schema::table('activities', static function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', static function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
