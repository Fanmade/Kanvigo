<?php

namespace Database\Factories;

use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
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
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            // Default to a working status; the terminal Canceled state is opt-in.
            'status' => fake()->randomElement(Status::columns()),
            'due_date' => null,
            // task_number is assigned atomically by the HasScopedNumber trait.
        ];
    }

    public function status(Status $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * Nest the task under the given parent, inheriting its project.
     */
    public function childOf(Task $parent): static
    {
        return $this->state(fn () => [
            'parent_id' => $parent->getKey(),
            'project_id' => $parent->project_id,
        ]);
    }

    /**
     * Mark the task as archived.
     */
    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    public function priority(Priority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    /**
     * Give the task a due date.
     */
    public function dueOn(string $date): static
    {
        return $this->state(fn () => ['due_date' => $date]);
    }
}
