<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-references between items (KAN-439): a directed link where a source
     * item references a target item, across tasks and docs. The "backlink" is
     * the same row read from the target's side, so a link is stored once. Unlike
     * a dependency this is not a blocker and may be circular.
     *
     * Named `item_references` rather than `references` to avoid the SQL reserved
     * word (portable across SQLite and PostgreSQL).
     */
    public function up(): void
    {
        Schema::create('item_references', function (Blueprint $table): void {
            $table->id();
            $table->morphs('source');
            $table->morphs('target');
            $table->timestamps();

            // A given source references a given target at most once.
            $table->unique(['source_type', 'source_id', 'target_type', 'target_id'], 'item_references_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_references');
    }
};
