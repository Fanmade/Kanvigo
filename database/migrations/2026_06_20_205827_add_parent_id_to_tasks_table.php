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
        Schema::table('tasks', static function (Blueprint $table): void {
            // Self-referential parent: a task may nest under another task. Deleting a
            // parent deletes its whole subtree. Postgres and SQLite do not auto-index
            // foreign keys, so index it explicitly for the recursive descendant queries.
            $table->foreignId('parent_id')
                ->nullable()
                ->after('story_id')
                ->constrained('tasks')
                ->cascadeOnDelete();

            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
