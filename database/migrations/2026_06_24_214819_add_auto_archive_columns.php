<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            // When the task entered the Done state (cleared when it leaves Done).
            // Drives auto-archiving of long-completed tasks.
            $table->timestamp('completed_at')->nullable()->after('archived_at');
        });

        Schema::table('projects', static function (Blueprint $table): void {
            // Per-project auto-archive threshold in days: null inherits the global
            // default, 0 disables auto-archiving for this project.
            $table->unsignedInteger('auto_archive_days')->nullable()->after('description');
        });

        // Seed completed_at for tasks already Done so they become eligible based on
        // when they were last touched, rather than waiting for their next save.
        DB::table('tasks')
            ->where('status', 'Done')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });

        Schema::table('projects', static function (Blueprint $table): void {
            $table->dropColumn('auto_archive_days');
        });
    }
};
