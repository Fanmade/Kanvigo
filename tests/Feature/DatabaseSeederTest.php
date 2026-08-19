<?php

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('refuses to seed when no credentials are configured', function (): void {
    config(['admin.email' => null, 'admin.password' => null]);

    // Registration is invitation-only, so seeding without an administrator would
    // report success and leave an instance nobody can sign in to.
    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'ADMIN_EMAIL and ADMIN_PASSWORD');

    expect(User::count())->toBe(0);
});

it('names the missing variable when only one of the credentials is configured', function (): void {
    config(['admin.email' => 'admin@kanvigo.test', 'admin.password' => null]);

    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'ADMIN_PASSWORD is missing');

    config(['admin.email' => null, 'admin.password' => 'super-secret']);

    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'ADMIN_EMAIL is missing');

    expect(User::count())->toBe(0);
});

it('only warns about missing credentials locally, where the demo seeder provides an admin', function (): void {
    config(['admin.email' => null, 'admin.password' => null]);
    app()->detectEnvironment(static fn (): string => 'local');

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->whereNotNull('id')->exists())->toBeTrue();
});

it('creates an admin with every permission from the configured credentials', function (): void {
    config([
        'admin.name' => 'Admin',
        'admin.email' => 'admin@kanvigo.test',
        'admin.password' => 'super-secret',
    ]);

    $this->seed(DatabaseSeeder::class);

    $admin = User::sole();

    expect($admin->name)->toBe('Admin')
        ->and($admin->email)->toBe('admin@kanvigo.test')
        ->and(Hash::check('super-secret', $admin->password))->toBeTrue();

    foreach (Permission::cases() as $permission) {
        expect($admin->hasPermission($permission))->toBeTrue();
    }
});

it('uses the configured name', function (): void {
    config([
        'admin.name' => 'Ben',
        'admin.email' => 'ben@kanvigo.test',
        'admin.password' => 'super-secret',
    ]);

    $this->seed(DatabaseSeeder::class);

    expect(User::sole()->name)->toBe('Ben');
});

it('falls back to the Admin name when none is configured', function (): void {
    config([
        'admin.name' => null,
        'admin.email' => 'admin@kanvigo.test',
        'admin.password' => 'super-secret',
    ]);

    $this->seed(DatabaseSeeder::class);

    expect(User::sole()->name)->toBe('Admin');
});

it('leaves an existing admin untouched, password included', function (): void {
    config([
        'admin.email' => 'admin@kanvigo.test',
        'admin.password' => 'super-secret',
    ]);

    $this->seed(DatabaseSeeder::class);

    config(['admin.password' => 'a-different-password']);

    $this->seed(DatabaseSeeder::class);

    expect(Hash::check('super-secret', User::sole()->password))->toBeTrue();
});

it('does not create a duplicate admin when seeded twice', function (): void {
    config([
        'admin.email' => 'admin@kanvigo.test',
        'admin.password' => 'super-secret',
    ]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'admin@kanvigo.test')->count())->toBe(1);
});
