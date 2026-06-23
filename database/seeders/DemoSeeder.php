<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Project;
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
        $admin = $this->resolveAdmin();

        $members = User::factory(3)->create();
        $everyone = $members->push($admin);

        $project = Project::factory()
            ->create(['title' => 'Kanvigo Demo', 'short_name' => 'KAN']);
        $project->members()->sync($everyone->pluck('id'));

        foreach (range(1, 4) as $i) {
            $rootTask = Task::factory()->for($project)->create([
                'title' => "Demo work stream {$i}",
            ]);
            $rootTask->assignees()->sync($everyone->random(rand(1, 2))->pluck('id'));

            foreach (Status::cases() as $status) {
                $child = Task::factory()->for($project)->childOf($rootTask)->status($status)->create();
                $child->assignees()->sync($everyone->random(rand(1, 2))->pluck('id'));
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
     * Resolve the administrator to center the demo data around, falling back
     * to a freshly created admin when none was configured via the environment.
     */
    private function resolveAdmin(): User
    {
        $email = config('admin.email');

        if (filled($email) && ($admin = User::query()->where('email', $email)->first())) {
            return $admin;
        }

        return User::factory()->admin()->create([
            'name' => config('admin.name') ?: 'Admin',
            'email' => $email ?: 'admin@example.com',
        ]);
    }

    /**
     * Backdate a handful of "completed" activities, so the dashboard chart
     * shows progress over the last two weeks.
     *
     * @param  Collection<int, Task>  $tasks
     */
    private function seedCompletionActivity(User $admin, Collection $tasks): void
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
