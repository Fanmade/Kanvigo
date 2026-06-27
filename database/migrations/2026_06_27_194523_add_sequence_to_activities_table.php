<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a stable, per-subject ordinal so an activity can be addressed as
     * "KAN-42-log-2" (the 2nd entry recorded for task KAN-42). The column is
     * backfilled for existing rows by their creation order (id ascending)
     * within each subject before the unique constraint is enforced.
     */
    public function up(): void
    {
        Schema::table('activities', static function (Blueprint $table): void {
            $table->unsignedInteger('sequence')->nullable()->after('subject_id');
        });

        // Each row's ordinal is the number of same-subject rows created no later
        // than it (id ascending). Portable across SQLite and PostgreSQL.
        DB::statement(<<<'SQL'
            UPDATE activities SET sequence = (
                SELECT COUNT(*) FROM activities AS earlier
                WHERE earlier.subject_type = activities.subject_type
                  AND earlier.subject_id = activities.subject_id
                  AND earlier.id <= activities.id
            )
        SQL);

        Schema::table('activities', static function (Blueprint $table): void {
            $table->unique(['subject_type', 'subject_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', static function (Blueprint $table): void {
            $table->dropUnique(['subject_type', 'subject_id', 'sequence']);
            $table->dropColumn('sequence');
        });
    }
};
