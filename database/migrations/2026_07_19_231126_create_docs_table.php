<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Docs are project-scoped, statusless reference pages (KAN-438). Unlike a
     * personal Note, a doc always belongs to a project (non-null project_id) and
     * inherits access from it. `doc_number` is a per-project sequence powering the
     * human reference "PROJ-D<n>"; `parent_id` groups docs into a tree.
     */
    public function up(): void
    {
        Schema::create('docs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('docs')->nullOnDelete();
            $table->unsignedInteger('doc_number');
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('is_public')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // The scope for the per-project number; the unique constraint is what
            // makes HasScopedNumber's optimistic retry safe under concurrency.
            $table->unique(['project_id', 'doc_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docs');
    }
};
