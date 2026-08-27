<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', static function (Blueprint $table): void {
            $table->unsignedInteger('waiting_nudge_days')->nullable()->after('auto_archive_days');
        });

        Schema::table('tasks', static function (Blueprint $table): void {
            $table->timestamp('waiting_nudged_at')->nullable()->after('waiting_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', static function (Blueprint $table): void {
            $table->dropColumn('waiting_nudge_days');
        });

        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('waiting_nudged_at');
        });
    }
};
