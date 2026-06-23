<?php

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('does not create an admin when no credentials are configured', function (): void {
    config(['admin.email' => null, 'admin.password' => null]);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0);
});

it('does not create an admin when only the email is configured', function (): void {
    config(['admin.email' => 'admin@kanvigo.test', 'admin.password' => null]);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0);
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

it('does not create a duplicate admin when seeded twice', function (): void {
    config([
        'admin.email' => 'admin@kanvigo.test',
        'admin.password' => 'super-secret',
    ]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'admin@kanvigo.test')->count())->toBe(1);
});
