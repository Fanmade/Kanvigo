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
        Schema::table('comments', static function (Blueprint $table) {
            $table->boolean('is_deleted')->default(false)->after('body');
            $table->text('delete_reason')->nullable()->after('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comments', static function (Blueprint $table) {
            $table->dropColumn(['is_deleted', 'delete_reason']);
        });
    }
};
