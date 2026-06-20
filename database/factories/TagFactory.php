<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => $name,
            'color' => Tag::colorForName($name),
        ];
    }

    /**
     * Give the tag a specific color.
     */
    public function color(string $color): static
    {
        return $this->state(fn () => ['color' => $color]);
    }
}
