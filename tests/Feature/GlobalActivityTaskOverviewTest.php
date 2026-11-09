<?php

use App\Enums\Status;
use App\Livewire\Activity\GlobalActivityFeed;
use App\Livewire\Projects\ProjectShow;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create(['name' => 'Reader']);
    $this->other = User::factory()->create(['name' => 'Writer']);
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, [$this->member->id, $this->other->id]);

    // Everything a test sets up happens before the changes it then records, so
    // "latest" by time and by id agree, as they do in production.
    Carbon::setTestNow('2026-10-01 08:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Record an activity on the task as the given user at the given time.
 */
function changeAt(Task $task, User $actor, string $at, string $newStatus = 'done'): Activity
{
    Carbon::setTestNow($at);
    test()->actingAs($actor);
    seedActivity($task, 'status_changed', 'status', 'planned', $newStatus);

    return Activity::query()->orderByDesc('id')->firstOrFail();
}

/**
 * The task overview's rows as [task id => latest activity id], in display order.
 *
 * @return array<int, int>
 */
function overviewRows(mixed $component): array
{
    return collect($component->instance()->updatedTasks()->items())
        ->mapWithKeys(static fn (Activity $activity): array => [$activity->subject_id => $activity->id])
        ->all();
}

function taskOverview(User $reader, array $params = []): mixed
{
    return Livewire::actingAs($reader)
        ->withQueryParams(['view' => 'tasks', ...$params])
        ->test(GlobalActivityFeed::class);
}

it('lists each task once, most recently changed first, with its latest change', function () {
    $quiet = Task::factory()->for($this->project)->create();
    $busy = Task::factory()->for($this->project)->create();

    changeAt($busy, $this->other, '2026-11-01 09:00');
    changeAt($quiet, $this->other, '2026-11-02 09:00');
    $latest = changeAt($busy, $this->other, '2026-11-03 09:00');

    $rows = overviewRows(taskOverview($this->member));

    expect(array_keys($rows))->toBe([$busy->id, $quiet->id])
        ->and($rows[$busy->id])->toBe($latest->id);
});

it('takes the latest change that passes the filters, not the latest overall', function () {
    $task = Task::factory()->for($this->project)->create();
    $third = User::factory()->create();
    joinProject($this->project, $third);

    $byWriter = changeAt($task, $this->other, '2026-11-01 09:00');
    changeAt($task, $third, '2026-11-02 09:00');

    $rows = overviewRows(taskOverview($this->member, ['actor' => $this->other->public_id]));

    expect($rows)->toBe([$task->id => $byWriter->id]);
});

it('filters the tasks by status, tag and text', function () {
    $done = Task::factory()->for($this->project)->status(Status::Done)->create(['title' => 'Ship the release']);
    $open = Task::factory()->for($this->project)->status(Status::ToDo)->create(['title' => 'Write the changelog']);

    $foreign = Project::factory()->create(['short_name' => 'XYZ']);
    joinProject($foreign, [$this->member->id, $this->other->id]);
    $elsewhere = Task::factory()->for($foreign)->status(Status::ToDo)->create(['title' => 'Unrelated']);

    // The same tag name in two projects is one filter option and matches both.
    $done->tags()->attach(Tag::factory()->for($this->project)->create(['name' => 'Backend']));
    $elsewhere->tags()->attach(Tag::factory()->for($foreign)->create(['name' => 'backend']));

    foreach ([$done, $open, $elsewhere] as $task) {
        changeAt($task, $this->other, '2026-11-01 09:00');
    }

    expect(array_keys(overviewRows(taskOverview($this->member, ['status' => Status::Done->value]))))->toBe([$done->id])
        ->and(array_keys(overviewRows(taskOverview($this->member, ['tag' => 'BACKEND']))))->toEqualCanonicalizing([$done->id, $elsewhere->id])
        ->and(array_keys(overviewRows(taskOverview($this->member, ['search' => 'changelog']))))->toBe([$open->id])
        ->and(array_keys(overviewRows(taskOverview($this->member, ['search' => 'xyz-'.$elsewhere->task_number]))))->toBe([$elsewhere->id])
        ->and(taskOverview($this->member)->instance()->tagOptions())->toHaveCount(1);
});

it('shows only tasks from projects the reader can see', function () {
    $mine = Task::factory()->for($this->project)->create();
    $foreign = Project::factory()->create();
    $outsider = User::factory()->create();
    joinProject($foreign, $outsider);
    $hidden = Task::factory()->for($foreign)->create();

    changeAt($mine, $this->other, '2026-11-01 09:00');
    changeAt($hidden, $outsider, '2026-11-01 09:00');

    expect(array_keys(overviewRows(taskOverview($this->member))))->toBe([$mine->id]);
});

it('renders a row per task with a link, status and the latest change', function () {
    $task = Task::factory()->for($this->project)->status(Status::InProgress)->create(['title' => 'Overview row']);
    changeAt($task, $this->other, '2026-11-01 09:00')->forceFill(['token_name' => 'Claude'])->save();

    taskOverview($this->member)
        ->assertSeeHtml('data-test="updated-task-row"')
        ->assertSeeHtml('href="'.route('task.show', ['short_name' => 'ABC', 'task_number' => $task->task_number]).'"')
        ->assertSeeHtml('data-test="activity-source"')
        ->assertSee('Overview row');
});

it('keeps the view in the URL and clearing filters keeps the view', function () {
    taskOverview($this->member, ['status' => Status::Done->value])
        ->assertSet('view', 'tasks')
        ->assertSet('isFiltered', true)
        ->call('clearFilters')
        ->assertSet('status', '')
        ->assertSet('view', 'tasks');
});

it('renders the task overview without a query per task', function () {
    $countQueries = function (int $tasks): int {
        foreach (Task::factory()->count($tasks)->for($this->project)->create() as $task) {
            $task->tags()->attach(Tag::factory()->for($this->project)->create());
            changeAt($task, $this->other, '2026-11-01 09:00');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        taskOverview($this->member)->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $small = $countQueries(2);
    $large = $countQueries(20);

    expect($large)->toBeLessThanOrEqual($small);
});

it('links from the project page to its task overview', function () {
    Livewire::actingAs($this->member)
        ->test(ProjectShow::class, ['short_name' => 'ABC'])
        ->assertSeeHtml('data-test="project-recent-updates-link"')
        ->assertSeeHtml(e(route('activity.index', ['project' => 'ABC', 'view' => 'tasks'])));
});
