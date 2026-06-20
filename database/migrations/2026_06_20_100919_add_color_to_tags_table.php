<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a color to tags so they can be rendered with a colored dot/badge.
     * Existing tags fall back to the neutral "zinc" default.
     */
    public function up(): void
    {
        Schema::table('tags', static function (Blueprint $table): void {
            $table->string('color')->default('zinc')->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags', static function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
