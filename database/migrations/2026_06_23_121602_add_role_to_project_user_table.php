<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give each project membership a role (owner / admin / member). New rows
     * default to member; existing ones are backfilled so the project creator —
     * the first member attached at creation, i.e. the earliest membership — owns
     * the project, and everyone else stays a member.
     */
    public function up(): void
    {
        Schema::table('project_user', static function (Blueprint $table): void {
            $table->string('role')->default('member')->after('user_id');
        });

        $earliestPerProject = DB::table('project_user')
            ->selectRaw('min(id) as id')
            ->groupBy('project_id')
            ->pluck('id');

        DB::table('project_user')->whereIn('id', $earliestPerProject)->update(['role' => 'owner']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_user', static function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
