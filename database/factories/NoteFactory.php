<?php

namespace Database\Factories;

use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * Define the model's default state: a private, projectless note.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'is_public' => false,
            'title' => fake()->sentence(4),
            'body' => '<p>'.fake()->paragraph().'</p>',
        ];
    }

    /**
     * Attached to a project but still private.
     */
    public function attachedTo(Project $project): static
    {
        return $this->state(fn (): array => [
            'project_id' => $project->id,
            'is_public' => false,
        ]);
    }

    /**
     * Public (read-only) to a project's members — which implies it is attached.
     */
    public function publicTo(Project $project): static
    {
        return $this->state(fn (): array => [
            'project_id' => $project->id,
            'is_public' => true,
        ]);
    }

    /**
     * Pinned to the top of the owner's notes list.
     */
    public function pinned(): static
    {
        return $this->state(fn (): array => ['is_pinned' => true]);
    }
}
