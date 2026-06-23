<?php

use App\Models\Tag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tags created before the color feature were defaulted to the neutral
     * "zinc", and {@see Tag::colorForName()} only runs for newly created tags —
     * so every pre-existing tag renders grey. Backfill those still on the
     * default with their deterministic palette color, the same value a fresh
     * tag of that name would receive.
     */
    public function up(): void
    {
        DB::table('tags')->where('color', 'zinc')->orderBy('id')
            ->each(static function (object $tag): void {
                DB::table('tags')->where('id', $tag->id)
                    ->update(['color' => Tag::colorForName($tag->name)]);
            });
    }

    /**
     * No-op: the original colors are not recoverable, and "zinc" was only ever
     * a default placeholder, so there is nothing meaningful to revert to.
     */
    public function down(): void
    {
        //
    }
};
