<?php

namespace App\Console\Commands;

use App\Authorization\AccountPermissionProvisioner;
use App\Enums\Permission;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Permission as CatalogPermission;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('permissions:sync
    {--grant= : Also grant a permission to this user, by email}
    {--permission= : The permission to grant, by its value (e.g. impersonate-users)}')]
#[Description('Provision the account-permission catalog and its global roles from the Permission enum.')]
class SyncAccountPermissions extends Command
{
    /**
     * Account permissions are otherwise provisioned lazily — a new enum case only
     * reaches the database when someone first grants it. That is enough for the
     * UI, where the chip is offered whether or not the row exists yet, but it
     * leaves a deploy with no deterministic step and no way to hand out a brand
     * new permission from a shell when nobody holds it yet.
     *
     * Idempotent by construction: the provisioner fills in only what is missing,
     * so this is safe to run on every deploy.
     */
    public function handle(AccountPermissionProvisioner $provisioner): int
    {
        $before = CatalogPermission::query()->pluck('name');

        $roles = $provisioner->provision();

        $added = collect(array_keys($roles))->reject(static fn (string $name): bool => $before->contains($name));

        $this->info($added->isEmpty()
            ? 'Account permissions already in sync ('.count($roles).' known).'
            : 'Provisioned '.$added->count().' new permission(s): '.$added->implode(', ').'.');

        return $this->grantRequested($provisioner);
    }

    /**
     * Carry out the optional --grant/--permission pair. Both are required
     * together; neither given is the plain sync above.
     */
    private function grantRequested(AccountPermissionProvisioner $provisioner): int
    {
        $email = $this->option('grant');
        $permission = $this->option('permission');

        if ($email === null && $permission === null) {
            return self::SUCCESS;
        }

        if ($email === null || $permission === null) {
            $this->error('--grant and --permission must be given together.');

            return self::FAILURE;
        }

        $case = Permission::tryFrom($permission);

        if ($case === null) {
            $this->error("Unknown permission [{$permission}]. Known: ".collect(Permission::cases())
                ->map(static fn (Permission $known): string => $known->value)
                ->implode(', ').'.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with the email [{$email}].");

            return self::FAILURE;
        }

        if ($user->hasPermission($case)) {
            $this->info("{$user->name} already holds [{$case->value}].");

            return self::SUCCESS;
        }

        $provisioner->grant($user, $case);

        $this->info("Granted [{$case->value}] to {$user->name} <{$user->email}>.");

        return self::SUCCESS;
    }
}
