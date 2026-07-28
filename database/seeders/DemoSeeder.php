<?php

namespace Database\Seeders;

use App\Authorization\ProjectRoleProvisioner;
use App\Enums\CancelReason;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use App\Support\InlineReferenceParser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds a demo instance that looks like a real team's board rather than a pile
 * of lorem ipsum: two projects for a fictional stargazing app, staffed by a
 * named team, with typed and tagged tasks, subtasks, blockers, comments, notes,
 * a doc tree and a canceled and an archived example.
 */
class DemoSeeder extends Seeder
{
    /**
     * Seed a small set of demo data for local development.
     */
    public function run(ProjectRoleProvisioner $provisioner): void
    {
        $admin = $this->resolveAdmin();
        $team = $this->seedTeam($admin);

        $app = $this->seedProject($provisioner, $team, [
            'title' => 'Perihelion',
            'short_name' => 'PER',
            'description' => '<p>The stargazing planner: a sky map, an observation planner and '
                .'the alerts that get people outside at the right moment.</p>',
        ]);

        $site = $this->seedProject($provisioner, $team, [
            'title' => 'Perihelion Website',
            'short_name' => 'WEB',
            'description' => '<p>The marketing site and the public docs. Small board, quick turnaround.</p>',
        ]);

        $this->seedAppBoard($app, $team);
        $this->seedWebsiteBoard($site, $team);

        // Make sure the admin has actionable tasks on their dashboard.
        Task::query()
            ->whereIn('status', [Status::InProgress->value, Status::ToDo->value])
            ->get()
            ->each(static fn (Task $task) => $task->assignees()->syncWithoutDetaching([$admin->id]));

        $this->shuffleBoardOrder();
        $this->seedDocs($app);
        $this->seedNotes($app, $team);
        $this->seedCompletionActivity($admin, Task::all());
    }

    /**
     * Give the seeded cards a hand-arranged board order.
     *
     * Cards sort by their drag-and-drop `position` and fall back to the id, so
     * seeding them top-down leaves every column reading strictly by task number
     * — parents above their own subtasks, oldest first — which no real board
     * ever looks like. Assigning a shuffled position per column mimics a team
     * that has ordered its lanes by what it picks up next.
     */
    private function shuffleBoardOrder(): void
    {
        foreach (Status::columns() as $status) {
            foreach (Task::query()->where('status', $status)->pluck('id')->shuffle() as $index => $id) {
                // A plain query update: repositioning is not an edit worth
                // recording in the activity feed of a fresh demo install.
                Task::query()->whereKey($id)->update(['position' => $index + 1]);
            }
        }
    }

    /**
     * The demo team: the configured administrator plus three named colleagues,
     * so avatars, initials and assignee lists read like real people.
     *
     * @return Collection<string, User>
     */
    private function seedTeam(User $admin): Collection
    {
        $colleagues = collect([
            'mara' => ['name' => 'Mara Osei', 'email' => 'mara@example.com'],
            'tom' => ['name' => 'Tom Berger', 'email' => 'tom@example.com'],
            'ines' => ['name' => 'Ines Alvarez', 'email' => 'ines@example.com'],
        ])->map(static fn (array $attributes): User => User::factory()->create($attributes));

        return $colleagues->put('admin', $admin);
    }

    /**
     * Create a project, give the whole team access to it and provision the
     * default task types.
     *
     * Membership alone grants nothing: ProjectPolicy resolves everything through
     * the delegated-permissions roles, so each member needs a project-scoped
     * role or every check (even view-project) fails.
     *
     * @param  Collection<string, User>  $team
     * @param  array{title: string, short_name: string, description: string}  $attributes
     */
    private function seedProject(ProjectRoleProvisioner $provisioner, Collection $team, array $attributes): Project
    {
        $project = Project::factory()->create($attributes);
        $project->members()->sync($team->pluck('id'));

        foreach ($team as $handle => $user) {
            $provisioner->syncMember($project, $user, $handle === 'admin' ? 'owner' : 'member');
        }

        TaskType::provisionDefaults($project);

        return $project;
    }

    /**
     * The main board: three work streams with subtasks across every status, plus
     * a blocked task, a canceled one and an archived one.
     *
     * @param  Collection<string, User>  $team
     */
    private function seedAppBoard(Project $project, Collection $team): void
    {
        $this->seedTags($project, ['Sky map', 'Planner', 'Mobile', 'API', 'Performance', 'Research']);

        $skyMap = $this->createTask($project, $team, [
            'title' => 'Sky map',
            'description' => '<p>The interactive star chart people actually open the app for.</p>',
            // The streams carry the status their subtasks imply — the same place
            // the app itself would put them once a subtask starts.
            'status' => Status::InProgress,
            'priority' => Priority::High,
            'assignees' => ['mara'],
        ]);

        $this->createTask($project, $team, [
            'title' => 'Render the Messier catalogue',
            'description' => '<p>All 110 objects, drawn from the bundled catalogue so the first '
                .'load works without a network connection.</p>',
            'status' => Status::Done,
            'type' => 'Feature',
            'tags' => ['Sky map'],
            'assignees' => ['mara'],
        ], $skyMap);

        $this->createTask($project, $team, [
            'title' => 'Light-pollution overlay',
            'description' => '<p>Shade the map by Bortle class so a city dweller can see at a glance '
                .'what is realistically visible tonight.</p><p>The tiles are ready; the legend is not.</p>',
            'status' => Status::InProgress,
            'type' => 'Feature',
            'priority' => Priority::High,
            'tags' => ['Sky map', 'Research'],
            'assignees' => ['mara', 'ines'],
        ], $skyMap);

        $this->createTask($project, $team, [
            'title' => 'Pinch-zoom stutters on older phones',
            'description' => '<p>Below about 30 fps the whole chart feels stuck to the finger. '
                .'Only reproducible on devices with a real GPU budget.</p>',
            'status' => Status::ToDo,
            'type' => 'Bug',
            'priority' => Priority::Highest,
            'tags' => ['Mobile', 'Performance'],
            'assignees' => ['tom'],
            'due_in_days' => 3,
        ], $skyMap);

        $this->createTask($project, $team, [
            'title' => 'Cache map tiles for offline use',
            'description' => '<p>Dark sites rarely have coverage. Whatever was on screen at home '
                .'should still be there in a field two hours later.</p>',
            'status' => Status::Planned,
            'type' => 'Chore',
            'tags' => ['Mobile', 'Performance'],
        ], $skyMap);

        $planner = $this->createTask($project, $team, [
            'title' => 'Observation planner',
            'description' => '<p>Turning "I would like to see Saturn" into a time, a direction and '
                .'a weather forecast.</p>',
            'status' => Status::InProgress,
            'priority' => Priority::High,
            'assignees' => ['tom'],
        ]);

        $bestHour = $this->createTask($project, $team, [
            'title' => 'Suggest the best hour for a target tonight',
            'description' => '<p>Combine altitude, moon phase and cloud cover into a single '
                .'recommended window, and say why it was picked.</p>',
            'status' => Status::InProgress,
            'type' => 'Feature',
            'priority' => Priority::High,
            'tags' => ['Planner'],
            'assignees' => ['tom'],
        ], $planner);

        $sharePlan = $this->createTask($project, $team, [
            'title' => 'Share a plan with a friend',
            'description' => '<p>A read-only link to tonight\'s plan. No account needed to open it.</p>',
            'status' => Status::Planned,
            'type' => 'Feature',
            'tags' => ['Planner', 'API'],
        ], $planner);

        // Sharing a plan cannot ship before there is a plan worth sharing: the
        // card shows as "Blocked" on the board until the blocker is done.
        $sharePlan->addBlocker($bestHour);

        $this->createTask($project, $team, [
            'title' => 'Twilight calculation is a few minutes off',
            'description' => '<p>Astronomical twilight was rounded to the nearest quarter hour, '
                .'which is close enough for a calendar and not for a telescope.</p>',
            'status' => Status::Done,
            'type' => 'Bug',
            'tags' => ['Planner'],
            'assignees' => ['ines'],
        ], $planner);

        $alerts = $this->createTask($project, $team, [
            'title' => 'Alerts & notifications',
            'description' => '<p>The reason someone puts a coat on and walks outside.</p>',
            'status' => Status::ToDo,
            'assignees' => ['ines'],
        ]);

        $this->createTask($project, $team, [
            'title' => 'Push alert before an ISS flyover',
            'description' => '<p>Ten minutes of warning, with the direction to look and how bright '
                .'the pass will be.</p>',
            'status' => Status::ToDo,
            'type' => 'Feature',
            'priority' => Priority::High,
            'tags' => ['Mobile', 'API'],
            'assignees' => ['ines'],
            'due_in_days' => 9,
        ], $alerts);

        $this->createTask($project, $team, [
            'title' => 'Weekly digest of upcoming conjunctions',
            'description' => '<p>One email on Thursday evening, in time to plan the weekend.</p>',
            'status' => Status::Planned,
            'type' => 'Feature',
            'priority' => Priority::Low,
            'tags' => ['Planner'],
        ], $alerts);

        $this->createTask($project, $team, [
            'title' => 'Quiet hours',
            'description' => '<p>Nobody wants a notification at 03:00, however good the seeing is.</p>',
            'status' => Status::Planned,
            'type' => 'Chore',
            'priority' => Priority::Low,
            'tags' => ['Mobile'],
        ], $alerts);

        $housekeeping = $this->createTask($project, $team, [
            'title' => 'Housekeeping',
            'description' => '<p>The work that keeps the rest of the board possible.</p>',
            'status' => Status::InProgress,
            'priority' => Priority::Low,
        ]);

        $this->createTask($project, $team, [
            'title' => 'Move to the new tile server',
            'description' => '<p>The old one is out of support in the autumn. Same tiles, '
                .'different signing scheme.</p>',
            'status' => Status::InProgress,
            'type' => 'Chore',
            'tags' => ['Performance'],
            'assignees' => ['tom'],
        ], $housekeeping);

        $this->createTask($project, $team, [
            'title' => 'Retire the old star-name importer',
            'description' => '<p>Superseded by the bundled catalogue — nothing left to import.</p>',
            'type' => 'Chore',
            'tags' => ['API'],
            'cancel' => CancelReason::Deprecated,
            'cancel_message' => 'The bundled catalogue replaced the import entirely.',
        ], $housekeeping);

        $this->createTask($project, $team, [
            'title' => 'Write the 0.9 release notes',
            'description' => '<p>Shipped, announced and filed away.</p>',
            'status' => Status::Done,
            'type' => 'Chore',
            'priority' => Priority::Low,
            'assignees' => ['mara'],
            'archived' => true,
        ], $housekeeping);

        $this->seedComments($team, $bestHour, $sharePlan);
    }

    /**
     * A second, deliberately small board, so the global board and the
     * cross-project filters have something to show.
     *
     * @param  Collection<string, User>  $team
     */
    private function seedWebsiteBoard(Project $project, Collection $team): void
    {
        $this->seedTags($project, ['Content', 'Design', 'SEO']);

        $this->createTask($project, $team, [
            'title' => 'Rewrite the landing page above the fold',
            'description' => '<p>Three lines and a screenshot. Everything else can wait for a scroll.</p>',
            'status' => Status::InProgress,
            'type' => 'Feature',
            'priority' => Priority::High,
            'tags' => ['Content', 'Design'],
            'assignees' => ['ines'],
        ]);

        $this->createTask($project, $team, [
            'title' => 'Dark screenshots for the feature grid',
            'description' => '<p>The current ones are from before the redesign and it shows.</p>',
            'status' => Status::ToDo,
            'type' => 'Chore',
            'tags' => ['Design'],
            'assignees' => ['mara'],
            'due_in_days' => 5,
        ]);

        $this->createTask($project, $team, [
            'title' => 'Pricing page returns a 404 from the footer link',
            'description' => '<p>The page moved with the last deploy; the footer did not.</p>',
            'status' => Status::ToDo,
            'type' => 'Bug',
            'priority' => Priority::Highest,
            'tags' => ['SEO'],
            'assignees' => ['tom'],
        ]);

        $this->createTask($project, $team, [
            'title' => 'Publish the launch post',
            'description' => '<p>Written, reviewed, scheduled.</p>',
            'status' => Status::Done,
            'type' => 'Chore',
            'tags' => ['Content'],
            'assignees' => ['ines'],
        ]);
    }

    /**
     * Create a demo task from a compact spec. Anything omitted falls back to the
     * factory defaults, so each caller only states what it cares about.
     *
     * @param  Collection<string, User>  $team
     * @param  array{
     *     title: string,
     *     description: string,
     *     status?: Status,
     *     priority?: Priority,
     *     type?: string,
     *     tags?: list<string>,
     *     assignees?: list<string>,
     *     due_in_days?: int,
     *     archived?: bool,
     *     cancel?: CancelReason,
     *     cancel_message?: string,
     * }  $spec
     */
    private function createTask(Project $project, Collection $team, array $spec, ?Task $parent = null): Task
    {
        $factory = Task::factory()->for($project)
            ->status($spec['status'] ?? Status::Planned)
            ->priority($spec['priority'] ?? Priority::default());

        if ($parent instanceof Task) {
            $factory = $factory->childOf($parent);
        }

        if (isset($spec['cancel'])) {
            $factory = $factory->canceled($spec['cancel'], $spec['cancel_message'] ?? null);
        }

        if ($spec['archived'] ?? false) {
            $factory = $factory->archived();
        }

        $task = $factory->create([
            'title' => $spec['title'],
            'description' => $spec['description'],
            'due_date' => isset($spec['due_in_days']) ? now()->addDays($spec['due_in_days'])->toDateString() : null,
            'task_type_id' => isset($spec['type'])
                ? $project->taskTypes()->whereNameLower($spec['type'])->value('id')
                : null,
        ]);

        if ($spec['tags'] ?? []) {
            $task->tags()->attach($project->tags()->whereIn('name', $spec['tags'])->pluck('id'));
        }

        if ($spec['assignees'] ?? []) {
            $task->assignees()->sync($team->only($spec['assignees'])->pluck('id'));
        }

        return $task;
    }

    /**
     * Create the project's tags, letting the model derive each color from its
     * name so the same tag looks the same everywhere.
     *
     * @param  list<string>  $names
     */
    private function seedTags(Project $project, array $names): void
    {
        foreach ($names as $name) {
            $project->tags()->create(['name' => $name]);
        }
    }

    /**
     * A short discussion on a couple of tasks, including a reply, so the comment
     * thread is not empty on a fresh install.
     *
     * @param  Collection<string, User>  $team
     */
    private function seedComments(Collection $team, Task $bestHour, Task $sharePlan): void
    {
        $question = $bestHour->comments()->create([
            'user_id' => $team['ines']->id,
            'body' => '<p>Does the recommended window account for the moon, or only for altitude? '
                .'A full moon ruins a faint target regardless of how high it sits.</p>',
        ]);

        $bestHour->comments()->create([
            'user_id' => $team['tom']->id,
            'parent_id' => $question->id,
            'body' => '<p>Moon phase is in, moon <em>distance</em> from the target is not. '
                .'Adding it is a one-liner once the ephemeris is loaded anyway.</p>',
        ]);

        $sharePlan->comments()->create([
            'user_id' => $team['mara']->id,
            'body' => '<p>Blocked until the planner picks a window worth sharing — the link would '
                .'be empty otherwise. Design is ready whenever it unblocks.</p>',
        ]);
    }

    /**
     * A pinned personal note and a note shared with the project, so both halves
     * of the notes feature are visible.
     *
     * @param  Collection<string, User>  $team
     */
    private function seedNotes(Project $project, Collection $team): void
    {
        $team['admin']->notes()->create([
            'title' => 'Before the beta',
            'body' => '<p>Check the tile cache on a real phone in a real field. '
                .'Everything else has been tested at a desk.</p>',
            'is_pinned' => true,
        ]);

        $team['mara']->notes()->create([
            'title' => 'Field-test kit',
            'body' => '<p>Red torch, spare battery, a printed sky chart for when the phone dies. '
                .'Shared here so nobody packs the same thing twice.</p>',
            'project_id' => $project->id,
            'is_public' => true,
        ]);
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
            'body' => '<ul><li>Run the test suite</li><li>Update the changelog</li>'
                .'<li>Check the sky map on a phone</li><li>Tag the release</li></ul>',
        ]);

        $project->docs()->create([
            'title' => 'Catalogue sources (work in progress)',
            'body' => '<p>Where the star and deep-sky data comes from, and what each source '
                .'costs us in licence terms. Still being written — only members who may edit '
                .'docs can see this draft.</p>',
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
