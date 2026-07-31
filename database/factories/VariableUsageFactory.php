<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\VariableUsage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<VariableUsage>
 */
class VariableUsageFactory extends Factory
{
    /**
     * Define the model's default state: a name used by a task of the project.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $project = Project::factory();

        return [
            'project_id' => $project,
            'name' => mb_strtolower(fake()->unique()->word().'_'.fake()->word()),
            'usable_type' => (new Task)->getMorphClass(),
            'usable_id' => Task::factory()->for($project),
        ];
    }

    /**
     * Record the usage against a specific item, in that item's project.
     */
    public function usedBy(Model $usable, int $projectId): static
    {
        return $this->state(fn () => [
            'project_id' => $projectId,
            'usable_type' => $usable->getMorphClass(),
            'usable_id' => $usable->getKey(),
        ]);
    }
}
