<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy project_user.role column. Project roles are now resolved
     * entirely through the delegated-permissions package (KAN-243); the pivot
     * remains only as the project membership link.
     */
    public function up(): void
    {
        Schema::table('project_user', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_user', function (Blueprint $table): void {
            $table->string('role')->default('member')->after('user_id');
        });
    }
};
