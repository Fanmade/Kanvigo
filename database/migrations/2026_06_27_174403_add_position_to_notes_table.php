<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->integer('position')->default(0)->after('is_pinned');
        });

        // Seed positions per owner by age (oldest = 1), so the default order —
        // position descending — keeps the existing newest-first listing until a
        // user reorders. New notes get the next position via the model hook.
        $counters = [];

        DB::table('notes')
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'user_id'])
            ->each(static function (object $note) use (&$counters): void {
                $counters[$note->user_id] = ($counters[$note->user_id] ?? 0) + 1;

                DB::table('notes')->where('id', $note->id)->update(['position' => $counters[$note->user_id]]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
