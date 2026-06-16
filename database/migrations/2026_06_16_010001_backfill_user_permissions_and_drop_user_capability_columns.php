<?php

use App\Enums\Permission;
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
        $now = now();

        DB::table('users')->where('can_create_projects', true)->orderBy('id')
            ->each(static function (object $user) use ($now): void {
                DB::table('user_permissions')->insertOrIgnore([
                    'user_id' => $user->id,
                    'permission' => Permission::CreateProjects->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        DB::table('users')->where('can_invite_users', true)->orderBy('id')
            ->each(static function (object $user) use ($now): void {
                DB::table('user_permissions')->insertOrIgnore([
                    'user_id' => $user->id,
                    'permission' => Permission::InviteUsers->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn(['can_create_projects', 'can_invite_users']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->boolean('can_create_projects')->default(false);
            $table->boolean('can_invite_users')->default(false);
        });

        DB::table('user_permissions')
            ->where('permission', Permission::CreateProjects->value)
            ->orderBy('id')
            ->each(static fn (object $row) => DB::table('users')
                ->where('id', $row->user_id)->update(['can_create_projects' => true]));

        DB::table('user_permissions')
            ->where('permission', Permission::InviteUsers->value)
            ->orderBy('id')
            ->each(static fn (object $row) => DB::table('users')
                ->where('id', $row->user_id)->update(['can_invite_users' => true]));
    }
};
