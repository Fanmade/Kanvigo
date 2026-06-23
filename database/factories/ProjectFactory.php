<?php

namespace Database\Factories;

use App\Authorization\ProjectRoleProvisioner;
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
     * Grant the given users access to the project as members after creation.
     *
     * @param  array<int, User>|Collection<int, User>  $users
     */
    public function withMembers(iterable $users): static
    {
        return $this->afterCreating(function (Project $project) use ($users): void {
            $provisioner = app(ProjectRoleProvisioner::class);

            foreach ($users as $user) {
                $project->members()->syncWithoutDetaching([$user->id]);
                $provisioner->syncMember($project, $user, 'member');
            }
        });
    }

    /**
     * Grant the given user access to the project with a specific role
     * (owner|admin|member, or a custom project role name).
     */
    public function withMember(User $user, string $role = 'member'): static
    {
        return $this->afterCreating(function (Project $project) use ($user, $role): void {
            $project->members()->syncWithoutDetaching([$user->id]);
            app(ProjectRoleProvisioner::class)->syncMember($project, $user, $role);
        });
    }

    /**
     * Grant the given user ownership of the project.
     */
    public function withOwner(User $user): static
    {
        return $this->withMember($user, 'owner');
    }
}
