<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DemoSeeder extends Seeder
{
    /**
     * Seed a small set of demo data for local development.
     */
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $members = User::factory(3)->create();
        $everyone = $members->push($admin);

        $project = Project::factory()
            ->create(['title' => 'Kanbrio Demo', 'short_name' => 'KAN']);
        $project->members()->sync($everyone->pluck('id'));

        foreach (range(1, 4) as $i) {
            $story = Story::factory()->for($project)->create();
            $story->assignees()->sync($everyone->random(rand(1, 2))->pluck('id'));

            foreach (Status::cases() as $status) {
                $task = Task::factory()->for($story)->status($status)->create();
                $task->assignees()->sync($everyone->random(rand(1, 2))->pluck('id'));
            }
        }

        // Make sure the admin has actionable tasks on their dashboard.
        Task::query()
            ->whereIn('status', [Status::InProgress->value, Status::ToDo->value])
            ->get()
            ->each(static fn (Task $task) => $task->assignees()->syncWithoutDetaching([$admin->id]));

        $this->seedCompletionActivity($admin, Task::all());
    }

    /**
     * Backdate a handful of "completed" activities so the dashboard chart
     * shows progress over the last two weeks.
     *
     * @param  Collection<int, Task>  $tasks
     */
    private function seedCompletionActivity(User $admin, $tasks): void
    {
        foreach (range(0, 13) as $offset) {
            $completions = rand(0, 3);

            for ($i = 0; $i < $completions; $i++) {
                $task = $tasks->random();

                $task->activities()->make([
                    'user_id' => $admin->id,
                    'action' => 'status_changed',
                    'field' => 'status',
                    'old_value' => Status::InProgress->value,
                    'new_value' => Status::Done->value,
                ])->forceFill([
                    'created_at' => now()->subDays($offset)->setTime(rand(9, 17), rand(0, 59)),
                ])->save();
            }
        }
    }
}
