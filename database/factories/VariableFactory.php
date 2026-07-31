<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Variable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variable>
 */
class VariableFactory extends Factory
{
    /**
     * Define the model's default state. Two words joined by an underscore always
     * satisfy the name pattern (a letter first, then lowercase word characters).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => mb_strtolower(fake()->unique()->word().'_'.fake()->word()),
            'description' => null,
            'value' => fake()->sentence(2),
        ];
    }

    /**
     * A variable that has no value yet — the placeholder state.
     */
    public function unset(): static
    {
        return $this->state(fn () => ['value' => null]);
    }

    /**
     * Give the variable a specific value.
     */
    public function value(?string $value): static
    {
        return $this->state(fn () => ['value' => $value]);
    }
}
