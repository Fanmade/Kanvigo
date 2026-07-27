<?php

use App\Enums\ReferenceOrigin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a cross-reference came from (KAN-441): `inline` links are derived
     * from the #references written in the source's rich text and are re-synced
     * on every save, while `manual` links are curated (the API and future link
     * actions) and are never touched by that sync. Existing rows predate inline
     * linking, so they default to `manual`.
     */
    public function up(): void
    {
        Schema::table('item_references', static function (Blueprint $table): void {
            $table->string('origin', 16)->default(ReferenceOrigin::Manual->value);
        });
    }

    public function down(): void
    {
        Schema::table('item_references', static function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
    }
};
