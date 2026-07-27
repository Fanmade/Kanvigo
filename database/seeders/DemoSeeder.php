<?php

namespace Database\Seeders;

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\InlineReferenceParser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DemoSeeder extends Seeder
{
    /**
     * Seed a small set of demo data for local development.
     */
    public function run(ProjectRoleProvisioner $provisioner): void
    {
        $admin = $this->resolveAdmin();

        $members = User::factory(3)->create();
        $everyone = $members->push($admin);

        $project = Project::factory()
            ->create(['title' => 'Kanvigo Demo', 'short_name' => 'KAN']);
        $project->members()->sync($everyone->pluck('id'));

        // Membership alone grants nothing: ProjectPolicy resolves everything
        // through the delegated-permissions roles, so each member needs a
        // project-scoped role or every check (even view-project) fails.
        foreach ($everyone as $user) {
            $provisioner->syncMember($project, $user, $user->is($admin) ? 'owner' : 'member');
        }

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

        $this->seedDocs($project);
        $this->seedCompletionActivity($admin, Task::all());
    }

    /**
     * Seed a small doc tree so the reference docs feature has something to show
     * out of the box: a published handbook with two nested pages, one of them
     * citing a demo task — the inline reference links the two, giving that task
     * a backlink — plus a draft, so the draft/published split is visible too.
     */
    private function seedDocs(Project $project): void
    {
        $task = $project->tasks()->orderBy('task_number')->firstOrFail();

        $handbook = $project->docs()->create([
            'title' => 'Team handbook',
            'is_public' => true,
            'body' => '<p>How this team works: the pages below are the short version. '
                .'Docs are project knowledge that has no status — specs, decisions and background '
                .'that outlive any single task.</p>',
        ]);

        $project->docs()->create([
            'title' => 'Definition of done',
            'parent_id' => $handbook->id,
            'is_public' => true,
            'body' => '<p>A task is done when it is reviewed, tested and documented. '
                .'The current example is '.$this->inlineReference($task).', which follows this checklist.</p>',
        ]);

        $project->docs()->create([
            'title' => 'Release checklist',
            'parent_id' => $handbook->id,
            'is_public' => true,
            'body' => '<ul><li>Run the test suite</li><li>Update the changelog</li><li>Tag the release</li></ul>',
        ]);

        $project->docs()->create([
            'title' => 'Onboarding notes (work in progress)',
            'body' => '<p>Still being written — only members who may edit docs can see this draft.</p>',
        ]);
    }

    /**
     * The markup the rich-text editor writes for an inline #reference, so seeded
     * bodies produce real cross-references (and backlinks) on save.
     *
     * @see InlineReferenceParser
     */
    private function inlineReference(Task $task): string
    {
        return '<a class="reference" data-type="reference" data-item-type="task"'
            .' data-id="'.$task->getKey().'" data-label="'.$task->reference.'"'
            .' href="/'.$task->reference.'">'.$task->reference.'</a>';
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
