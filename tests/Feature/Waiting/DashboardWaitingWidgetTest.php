<?php

use App\Actions\SetWaitingOn;
use App\Enums\Status;
use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Alpha']);
    $this->me = userWithRole($this->project, 'member');
    $this->asker = userWithRole($this->project, 'member');
});

/** Put a task into the waiting state as `$by`, waiting on `$on`. */
function dashboardWaitFor(Task $task, User $on, User $by): Task
{
    Auth::login($by);
    app(SetWaitingOn::class)->handle($task, $on);
    Auth::logout();

    return $task->fresh();
}

it('lists the tasks waiting on the viewer, with who asked', function () {
    $task = dashboardWaitFor(Task::factory()->for($this->project)->create(['title' => 'Needs an answer']), $this->me, $this->asker);

    Livewire::actingAs($this->me)
        ->test(Dashboard::class)
        ->assertSeeHtml('data-test="dashboard-waiting-'.$task->id.'"')
        ->assertSee($task->reference)
        ->assertSee($this->asker->name);
});

it('leaves out tasks waiting on somebody else', function () {
    $mine = dashboardWaitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
    $theirs = dashboardWaitFor(Task::factory()->for($this->project)->create(), $this->asker, $this->me);

    Livewire::actingAs($this->me)
        ->test(Dashboard::class)
        ->assertSeeHtml('data-test="dashboard-waiting-'.$mine->id.'"')
        ->assertDontSeeHtml('data-test="dashboard-waiting-'.$theirs->id.'"');
});

it('hides the widget entirely when nothing is waiting', function () {
    Livewire::actingAs($this->me)
        ->test(Dashboard::class)
        ->assertDontSeeHtml('data-test="dashboard-waiting"');
});

it('caps the list at five while counting them all', function () {
    $tasks = collect(range(1, 8))->map(fn (int $i): Task => dashboardWaitFor(
        Task::factory()->for($this->project)->create(['title' => "Question {$i}"]),
        $this->me,
        $this->asker,
    ));

    $component = Livewire::actingAs($this->me)->test(Dashboard::class);

    expect($component->get('waitingOnMeCount'))->toBe(8);

    // The five oldest are listed; the rest only live behind "See all".
    $tasks->take(5)->each(fn (Task $task) => $component->assertSeeHtml('data-test="dashboard-waiting-'.$task->id.'"'));
    $tasks->slice(5)->each(fn (Task $task) => $component->assertDontSeeHtml('data-test="dashboard-waiting-'.$task->id.'"'));
});

it('lists the oldest waits first', function () {
    $recent = dashboardWaitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
    $recent->forceFill(['waiting_since' => now()->subDay()])->save();

    $oldest = dashboardWaitFor(Task::factory()->for($this->project)->create(), $this->me, $this->asker);
    $oldest->forceFill(['waiting_since' => now()->subMonth()])->save();

    $shown = Livewire::actingAs($this->me)->test(Dashboard::class)->get('waitingOnMeShown');

    expect($shown->pluck('id')->all())->toBe([$oldest->id, $recent->id]);
});

it('renders the widget without a query per listed item', function () {
    $queriesToRender = function (int $items): int {
        $project = Project::factory()->create();
        $me = userWithRole($project, 'member');

        // Pinned to Planned so the tasks stay out of the unrelated "My tasks"
        // panel: the factory's random working status would otherwise let that
        // panel's own eager load appear in one run and not the other, and the
        // budget would measure the dashboard's other half instead of the widget.
        for ($i = 0; $i < $items; $i++) {
            $asker = userWithRole($project, 'member');
            dashboardWaitFor(Task::factory()->for($project)->status(Status::Planned)->create(), $me, $asker);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($me)->test(Dashboard::class)->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesToRender(5))->toBeLessThanOrEqual($queriesToRender(1));
});
