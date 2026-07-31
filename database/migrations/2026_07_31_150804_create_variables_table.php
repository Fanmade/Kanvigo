<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A variable is a project-scoped named stand-in for a fact — `[hero]` in
     * prose resolving to "Robin Hood" (KAN-457). Names are stored lower-cased
     * and constrained to a strict pattern, so a plain unique index on
     * (project_id, name) suffices; no functional lower(name) index is needed.
     *
     * `value` is nullable because an unset variable is a normal state — the
     * placeholder workflow — not an error.
     */
    public function up(): void
    {
        Schema::create('variables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variables');
    }
};
