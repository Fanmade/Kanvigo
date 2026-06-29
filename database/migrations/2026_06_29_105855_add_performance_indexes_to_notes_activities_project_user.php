<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cover the hot per-user / per-project lookups that scan whole tables on
 * PostgreSQL (which, unlike the local SQLite, does not implicitly index the
 * child side of a foreign key):
 *
 * - notes: the owner's pinned-first, position-ordered list (dashboard, notes
 *   page, notes API) and a project's public notes.
 * - activities: the per-user feed and the dashboard's date-bounded progress
 *   chart over the highest-growth table.
 * - project_user: User::projects() filters on the trailing pivot column, which
 *   the existing (project_id, user_id) unique cannot serve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', static function (Blueprint $table): void {
            $table->index(['user_id', 'is_pinned', 'position']);
            $table->index(['project_id', 'is_public']);
        });

        Schema::table('activities', static function (Blueprint $table): void {
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('project_user', static function (Blueprint $table): void {
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notes', static function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'is_pinned', 'position']);
            $table->dropIndex(['project_id', 'is_public']);
        });

        Schema::table('activities', static function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at']);
        });

        Schema::table('project_user', static function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
        });
    }
};
