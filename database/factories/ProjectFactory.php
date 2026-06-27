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
     * Grant the given user access to the project with one or more roles
     * (owner|admin|member|viewer, or a custom project role name). Pass an array
     * to hold several at once (KAN-317).
     *
     * @param  string|list<string>  $role
     */
    public function withMember(User $user, string|array $role = 'member'): static
    {
        return $this->afterCreating(function (Project $project) use ($user, $role): void {
            $provisioner = app(ProjectRoleProvisioner::class);
            $roles = (array) $role;

            $project->members()->syncWithoutDetaching([$user->id]);
            $provisioner->syncMember($project, $user, $roles[0]);

            foreach (array_slice($roles, 1) as $extraRole) {
                $provisioner->addRole($project, $user, $extraRole);
            }
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
