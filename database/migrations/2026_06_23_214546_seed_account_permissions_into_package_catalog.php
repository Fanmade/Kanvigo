<?php

use App\Enums\Permission;
use Fanmade\DelegatedPermissions\Models\Permission as PackagePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Register Kanvigo's account-level permissions in the package's global
     * catalog so they resolve through the one engine, and the system role
     * (break-glass) grants them like any other permission (KAN-242). The
     * per-user grants stay in `user_permissions` for now.
     */
    public function up(): void
    {
        foreach (Permission::cases() as $permission) {
            PackagePermission::query()->firstOrCreate(['name' => $permission->value]);
        }
    }

    /**
     * No-op: the catalog permissions are left in place.
     */
    public function down(): void
    {
        //
    }
};
