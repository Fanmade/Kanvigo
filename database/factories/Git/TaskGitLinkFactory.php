<?php

namespace Database\Factories\Git;

use App\Git\PrState;
use App\Git\TaskGitLink;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskGitLink>
 */
class TaskGitLinkFactory extends Factory
{
    protected $model = TaskGitLink::class;

    /**
     * Define the model's default state: a reserved branch with no PR yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'branch_name' => 'feature/'.fake()->unique()->slug(3),
            'base_branch' => 'main',
            'pr_url' => null,
            'pr_number' => null,
            'pr_state' => PrState::None,
            'merge_commit_sha' => null,
            'opened_at' => null,
            'merged_at' => null,
        ];
    }

    /**
     * A link whose pull request is open.
     */
    public function open(): static
    {
        return $this->state(fn (): array => [
            'pr_url' => 'https://github.com/acme/repo/pull/'.fake()->numberBetween(1, 9999),
            'pr_number' => fake()->numberBetween(1, 9999),
            'pr_state' => PrState::Open,
            'opened_at' => now(),
        ]);
    }

    /**
     * A link whose pull request has been merged.
     */
    public function merged(): static
    {
        return $this->open()->state(fn (): array => [
            'pr_state' => PrState::Merged,
            'merge_commit_sha' => fake()->sha1(),
            'merged_at' => now(),
        ]);
    }
}
