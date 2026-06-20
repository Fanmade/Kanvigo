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
        Schema::table('activities', static function (Blueprint $table): void {
            // The name of the API/MCP token an action was performed with, or null
            // when it was performed directly in the web app. Display-only.
            $table->string('token_name')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', static function (Blueprint $table): void {
            $table->dropColumn('token_name');
        });
    }
};
