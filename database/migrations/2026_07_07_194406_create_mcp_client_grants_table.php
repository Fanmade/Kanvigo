<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A grant records a user's OAuth consent for one MCP client connection
     * (each dynamically registered client is one connection, e.g. one Claude
     * Desktop connector) together with its optional project restriction. Like
     * the API token restriction, the flag is authoritative — deleting an
     * allowed project narrows the grant instead of widening it back to every
     * project.
     */
    public function up(): void
    {
        Schema::create('mcp_client_grants', static function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('restricts_projects')->default(false);
            $table->timestamps();

            $table->unique(['oauth_client_id', 'user_id']);
        });

        Schema::create('mcp_client_grant_project', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mcp_client_grant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['mcp_client_grant_id', 'project_id'], 'mcp_grant_project_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_client_grant_project');
        Schema::dropIfExists('mcp_client_grants');
    }
};
