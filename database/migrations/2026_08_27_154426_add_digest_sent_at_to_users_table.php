<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this user was last sent a notification digest. Delivery state rather
     * than a preference, so it lives in a column the digest command can filter
     * on instead of the preferences JSON.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->timestamp('digest_sent_at')->nullable()->after('activities_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('digest_sent_at');
        });
    }
};
