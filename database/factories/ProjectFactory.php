<?php

namespace Database\Factories;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->company(),
            'short_name' => strtoupper(fake()->unique()->lexify('???')),
            'description' => fake()->paragraph(),
        ];
    }

    /**
     * Grant the given users access to the project after creation.
     *
     * @param  array<int, User>|Collection<int, User>  $users
     */
    public function withMembers(iterable $users): static
    {
        return $this->afterCreating(function (Project $project) use ($users): void {
            $project->members()->syncWithoutDetaching(
                collect($users)->pluck('id')->all()
            );
        });
    }

    /**
     * Grant the given user access to the project with a specific role.
     */
    public function withMember(User $user, ProjectRole $role = ProjectRole::Member): static
    {
        return $this->afterCreating(function (Project $project) use ($user, $role): void {
            $project->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
        });
    }

    /**
     * Grant the given user ownership of the project.
     */
    public function withOwner(User $user): static
    {
        return $this->withMember($user, ProjectRole::Owner);
    }
}
