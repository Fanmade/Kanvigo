<?php

namespace Database\Factories;

use App\Models\Doc;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doc>
 */
class DocFactory extends Factory
{
    /**
     * Define the model's default state: a private (draft) doc in a fresh project.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'parent_id' => null,
            'title' => fake()->sentence(4),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'is_public' => false,
        ];
    }

    /**
     * Published (readable by every project member), not a draft.
     */
    public function published(): static
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }

    /**
     * Nested under the given parent doc (inheriting its project).
     */
    public function childOf(Doc $parent): static
    {
        return $this->state(fn (): array => [
            'project_id' => $parent->project_id,
            'parent_id' => $parent->id,
        ]);
    }
}
