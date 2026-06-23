<?php

use App\Authorization\ProjectRoleProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed the project permission catalog eagerly so it always exists, rather
     * than only once the first project is provisioned. The system break-glass
     * role grants the permissions that exist, so the catalog must be present for
     * cross-project administration (KAN-240) to work from a clean install.
     */
    public function up(): void
    {
        app(ProjectRoleProvisioner::class)->seedCatalog();
    }

    /**
     * No-op: the catalog permissions are left in place.
     */
    public function down(): void
    {
        //
    }
};
