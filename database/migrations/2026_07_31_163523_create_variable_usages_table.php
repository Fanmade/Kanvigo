<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each variable name appears in a project's content (KAN-460): a
     * derived, eventually-consistent index behind "where is this used?", rename
     * and unknown-name surfacing. Nothing on the render path reads it.
     *
     * Rows are keyed on the *name*, not a variable id, and carry no foreign key
     * to `variables` — deliberately. A usage of a name no variable defines (yet,
     * or any more) is exactly what surfaces an unknown name, and integrity comes
     * from rebuilding the index from content, not from a constraint.
     */
    public function up(): void
    {
        Schema::create('variable_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->morphs('usable');
            $table->timestamps();

            // One row per name per item; the sync upserts against this.
            $table->unique(['project_id', 'name', 'usable_type', 'usable_id'], 'variable_usages_unique');

            // "Where is [name] used in this project", the panel's query.
            $table->index(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variable_usages');
    }
};
