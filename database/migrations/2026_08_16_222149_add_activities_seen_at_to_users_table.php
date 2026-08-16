<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the user last looked at the cross-project activity feed.
     *
     * One timestamp per user turns the feed into an inbox without a second
     * notification system: everything recorded after it is "new since your last
     * visit". Null means never visited, so nothing is marked new — a brand-new
     * account is not greeted with the entire history flagged as unread.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->timestamp('activities_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('activities_seen_at');
        });
    }
};
