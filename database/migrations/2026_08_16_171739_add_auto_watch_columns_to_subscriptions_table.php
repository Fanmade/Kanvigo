<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records how a subscription came about, and keeps a deliberate
     * unsubscribe on file instead of deleting the row: an automatic trigger
     * (assignment, comment, mention) must not re-subscribe someone who has
     * already opted out, and only a retained row can tell the two apart.
     *
     * Existing rows stay `auto = false` — they are treated as explicit, so no
     * one loses a subscription they set up by hand.
     */
    public function up(): void
    {
        Schema::table('subscriptions', static function (Blueprint $table): void {
            $table->boolean('auto')->default(false);
            $table->timestamp('unsubscribed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', static function (Blueprint $table): void {
            $table->dropColumn(['auto', 'unsubscribed_at']);
        });
    }
};
