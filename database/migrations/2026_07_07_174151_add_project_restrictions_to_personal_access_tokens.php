<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An API token may be restricted to a subset of the owner's projects. The
     * restriction is an explicit flag on the token — not inferred from pivot
     * rows — so deleting an allowed project narrows the token further instead
     * of silently widening it back to every project. Existing tokens default
     * to unrestricted and keep their current all-projects access.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', static function (Blueprint $table): void {
            $table->boolean('restricts_projects')->default(false);
        });

        Schema::create('personal_access_token_project', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['personal_access_token_id', 'project_id'], 'pat_project_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_token_project');

        Schema::table('personal_access_tokens', static function (Blueprint $table): void {
            $table->dropColumn('restricts_projects');
        });
    }
};
