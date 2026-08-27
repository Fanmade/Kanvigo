<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->foreignId('waiting_on_user_id')
                ->nullable()
                ->after('cancel_message')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('waiting_since')->nullable()->after('waiting_on_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('waiting_on_user_id');
            $table->dropColumn('waiting_since');
        });
    }
};
