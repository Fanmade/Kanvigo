<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a relationship type to each dependency link. Existing rows are all
     * blocking links, so they default to "blocks"; the unique key gains the type
     * so two tasks can carry several differently-typed links.
     */
    public function up(): void
    {
        Schema::table('dependencies', static function (Blueprint $table): void {
            $table->string('type')->default('blocks');
        });

        Schema::table('dependencies', static function (Blueprint $table): void {
            $table->dropUnique('dependencies_unique');
            $table->unique(
                ['dependent_type', 'dependent_id', 'blocker_type', 'blocker_id', 'type'],
                'dependencies_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dependencies', static function (Blueprint $table): void {
            $table->dropUnique('dependencies_unique');
            $table->unique(
                ['dependent_type', 'dependent_id', 'blocker_type', 'blocker_id'],
                'dependencies_unique',
            );
        });

        Schema::table('dependencies', static function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
