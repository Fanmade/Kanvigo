<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2).'.pdf';

        return [
            'attachable_id' => Task::factory(),
            'attachable_type' => (new Task)->getMorphClass(),
            'uploaded_by' => User::factory(),
            'disk' => (string) config('attachments.disk'),
            'path' => 'attachments/'.fake()->uuid().'.pdf',
            'name' => $name,
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 5_000_000),
        ];
    }

    /**
     * An inline image attachment (embedded in a rich-text document).
     */
    public function inline(): static
    {
        return $this->state(fn (): array => [
            'is_inline' => true,
            'name' => fake()->unique()->slug(2).'.png',
            'mime_type' => 'image/png',
            'path' => 'attachments/'.fake()->uuid().'.png',
        ]);
    }
}
