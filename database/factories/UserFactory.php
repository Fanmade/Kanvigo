<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the user can create projects.
     */
    public function canCreateProjects(): static
    {
        return $this->withPermission(Permission::CreateProjects);
    }

    /**
     * Indicate that the user can invite other users.
     */
    public function canInviteUsers(): static
    {
        return $this->withPermission(Permission::InviteUsers);
    }

    /**
     * Indicate that the user can create API tokens.
     */
    public function canCreateApiTokens(): static
    {
        return $this->withPermission(Permission::CreateApiTokens);
    }

    /**
     * Indicate that the user can manage other user accounts.
     */
    public function canManageUsers(): static
    {
        return $this->withPermission(Permission::ManageUsers);
    }

    /**
     * Indicate that the user is an administrator with all capabilities.
     */
    public function admin(): static
    {
        return $this->withPermission(...Permission::cases());
    }

    /**
     * Indicate that the user's account has been deactivated.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'deactivated_at' => now(),
        ]);
    }

    /**
     * Grant the given permissions to the user after it is created.
     */
    public function withPermission(Permission ...$permissions): static
    {
        return $this->afterCreating(function (User $user) use ($permissions): void {
            foreach ($permissions as $permission) {
                $user->permissions()->firstOrCreate(['permission' => $permission]);
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
