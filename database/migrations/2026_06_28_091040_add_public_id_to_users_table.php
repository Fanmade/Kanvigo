<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Give every user an opaque, stable public identifier (a ULID) used as the
     * route key for profile and avatar URLs — so those URLs never expose the
     * sequential primary key (which would leak the user count and invite
     * enumeration). Added nullable, backfilled, then made unique.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
        });

        // Backfill existing rows directly (no model events, so timestamps and the
        // avatar cache-buster are left untouched).
        foreach (DB::table('users')->whereNull('public_id')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['public_id' => (string) Str::ulid()]);
        }

        Schema::table('users', static function (Blueprint $table): void {
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
