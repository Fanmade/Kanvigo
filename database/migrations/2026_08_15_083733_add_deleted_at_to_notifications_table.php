<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dismissing a notification soft-deletes it: it leaves the panel and the
     * unread count at once, while remaining available to the inbox archive
     * until the retention prune removes it for good.
     */
    public function up(): void
    {
        Schema::table('notifications', static function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', static function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
