<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Story;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(Status::cases()),
            // task_number is assigned atomically by the HasScopedNumber trait.
        ];
    }

    public function status(Status $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
