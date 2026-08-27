<?php

use App\Enums\Permission;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Permission as CatalogPermission;
use Fanmade\DelegatedPermissions\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provisions the catalog and a global role for every permission', function () {
    Role::query()->whereNull('scope_type')->where('is_system', false)->delete();
    CatalogPermission::query()->delete();

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Provisioned')
        ->assertSuccessful();

    $names = collect(Permission::cases())->map(static fn (Permission $case): string => $case->value);

    expect(CatalogPermission::query()->whereIn('name', $names)->count())->toBe($names->count())
        ->and(Role::query()->whereNull('scope_type')->whereIn('name', $names)->count())->toBe($names->count());
});

it('is idempotent', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('already in sync')
        ->assertSuccessful();

    expect(Role::query()->whereNull('scope_type')->where('name', Permission::ImpersonateUsers->value)->count())->toBe(1);
});

it('grants a permission to a user by email', function () {
    $user = User::factory()->create();

    $this->artisan('permissions:sync', [
        '--grant' => $user->email,
        '--permission' => Permission::ImpersonateUsers->value,
    ])->assertSuccessful();

    expect($user->fresh()->hasPermission(Permission::ImpersonateUsers))->toBeTrue();
});

it('says so when the user already holds the permission', function () {
    $user = User::factory()->canImpersonateUsers()->create();

    $this->artisan('permissions:sync', [
        '--grant' => $user->email,
        '--permission' => Permission::ImpersonateUsers->value,
    ])->expectsOutputToContain('already holds')->assertSuccessful();
});

it('fails on a half-given grant, an unknown permission or an unknown user', function (array $options, string $message) {
    $this->artisan('permissions:sync', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'only --grant' => [['--grant' => 'someone@example.com'], 'must be given together'],
    'only --permission' => [['--permission' => 'impersonate-users'], 'must be given together'],
    'unknown permission' => [['--grant' => 'someone@example.com', '--permission' => 'nope'], 'Unknown permission'],
    'unknown user' => [['--grant' => 'nobody@example.com', '--permission' => 'impersonate-users'], 'No user with the email'],
]);
