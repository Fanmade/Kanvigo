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
        Schema::create('task_git_links', static function (Blueprint $table) {
            $table->id();
            // One git link per task: the reserved branch and, once opened, its PR.
            $table->foreignId('task_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('branch_name');
            $table->string('base_branch');
            $table->string('pr_url')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_state')->default('None');
            $table->string('merge_commit_sha', 40)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_git_links');
    }
};
