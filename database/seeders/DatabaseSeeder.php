<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->createAdminUser();

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }

    /**
     * Write a line to the console the seeder was started from. Every path that
     * runs a seeder here goes through Artisan (`db:seed`, `migrate --seed`, or
     * `Seeder::call()`), each of which sets the command.
     */
    private function report(string $message, bool $warning = false): void
    {
        $warning ? $this->command->warn($message) : $this->command->info($message);
    }

    /**
     * Create the configured administrator, granting every permission.
     *
     * Refuses rather than returning quietly when the credentials are missing:
     * registration is invitation-only, so a seed that reports success without
     * creating an account leaves an instance nobody can sign in to, and the
     * only way back in is a console session. The exception is a local
     * environment, where {@see DemoSeeder} provides a working account anyway.
     *
     * @throws RuntimeException when no usable administrator can be created
     */
    private function createAdminUser(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (blank($email) && blank($password)) {
            if (app()->environment('local')) {
                $this->report('No ADMIN_EMAIL/ADMIN_PASSWORD configured — the demo seeder will create an administrator instead.', warning: true);

                return;
            }

            throw new RuntimeException(
                'Cannot seed an administrator: set ADMIN_EMAIL and ADMIN_PASSWORD in .env and seed again. '
                .'Registration is invitation-only, so an instance without an administrator cannot be signed into.'
            );
        }

        if (blank($email) || blank($password)) {
            throw new RuntimeException(sprintf(
                'Incomplete administrator configuration: ADMIN_EMAIL and ADMIN_PASSWORD must both be set (%s is missing).',
                blank($email) ? 'ADMIN_EMAIL' : 'ADMIN_PASSWORD',
            ));
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->report(sprintf(
                'Administrator %s already exists — left untouched, and its password was not reset.',
                $email,
            ));

            return;
        }

        User::factory()->admin()->create([
            'name' => config('admin.name') ?: 'Admin',
            'email' => $email,
            'password' => $password,
        ]);

        $this->report(sprintf('Administrator %s created with every account permission.', $email));
    }
}
