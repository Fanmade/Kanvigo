<?php

use App\Actions\SetWaitingOn;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\WaitingReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    config()->set('kanvigo.tasks.waiting_nudge_days', 7);

    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->awaited = userWithRole($this->project, 'member');
    $this->asker = userWithRole($this->project, 'member');
});

/**
 * A task that has been waiting on `$this->awaited` for the given number of days.
 */
function requestWaitingFor(int $days, ?Project $project = null, ?User $awaited = null): Task
{
    $project ??= test()->project;
    $awaited ??= test()->awaited;

    $task = Task::factory()->for($project)->create();

    Auth::login(test()->asker);
    app(SetWaitingOn::class)->handle($task, $awaited);
    Auth::logout();

    $task->forceFill(['waiting_since' => Carbon::now()->subDays($days)])->save();

    return $task;
}

it('reminds only about requests older than the threshold', function () {
    $overdue = requestWaitingFor(10);
    $fresh = requestWaitingFor(2);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertSentTo(
        $this->awaited,
        WaitingReminder::class,
        fn (WaitingReminder $reminder): bool => $reminder->tasks->contains('id', $overdue->id)
            && ! $reminder->tasks->contains('id', $fresh->id),
    );
});

it('sends nothing when the project has reminders switched off', function () {
    $this->project->update(['waiting_nudge_days' => 0]);
    requestWaitingFor(30);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertNotSentTo($this->awaited, WaitingReminder::class);
});

it('sends nothing when reminders are off system-wide and the project inherits', function () {
    config()->set('kanvigo.tasks.waiting_nudge_days', 0);
    requestWaitingFor(30);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertNotSentTo($this->awaited, WaitingReminder::class);
});

it('honours a per-project threshold over the system default', function () {
    $this->project->update(['waiting_nudge_days' => 30]);
    requestWaitingFor(10);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertNotSentTo($this->awaited, WaitingReminder::class);
});

it('bundles a person\'s overdue requests into one notification', function () {
    requestWaitingFor(10);
    requestWaitingFor(12);
    requestWaitingFor(20);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertSentToTimes($this->awaited, WaitingReminder::class, 1);
    Notification::assertSentTo(
        $this->awaited,
        WaitingReminder::class,
        static fn (WaitingReminder $reminder): bool => $reminder->tasks->count() === 3,
    );
});

it('does not remind the same person again within the interval', function () {
    requestWaitingFor(10);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();
    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertSentToTimes($this->awaited, WaitingReminder::class, 1);
});

it('reminds again once another full interval has passed', function () {
    $task = requestWaitingFor(10);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    $task->fresh()->forceFill(['waiting_nudged_at' => Carbon::now()->subDays(8)])->save();

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertSentToTimes($this->awaited, WaitingReminder::class, 2);
});

it('leaves canceled and archived requests alone', function () {
    requestWaitingFor(10)->forceFill(['canceled_at' => Carbon::now()])->save();
    requestWaitingFor(10)->forceFill(['archived_at' => Carbon::now()])->save();

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertNotSentTo($this->awaited, WaitingReminder::class);
});

it('reminds each awaited person separately', function () {
    $other = userWithRole($this->project, 'member');
    requestWaitingFor(10);
    requestWaitingFor(10, awaited: $other);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertSentToTimes($this->awaited, WaitingReminder::class, 1);
    Notification::assertSentToTimes($other, WaitingReminder::class, 1);
});

it('starts the clock over when the wait is re-set', function () {
    $task = requestWaitingFor(10);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();
    expect($task->fresh()->waiting_nudged_at)->not->toBeNull();

    // Handing the request to somebody else is a new wait, not a nudged one.
    $other = userWithRole($this->project, 'member');
    Auth::login($this->asker);
    app(SetWaitingOn::class)->handle($task->fresh(), $other);
    Auth::logout();

    expect($task->fresh()->waiting_nudged_at)->toBeNull();
});

it('names the oldest request and counts the rest in the payload', function () {
    requestWaitingFor(10);
    $oldest = requestWaitingFor(40);

    $this->artisan('tasks:nudge-waiting')->assertSuccessful();

    Notification::assertSentTo($this->awaited, WaitingReminder::class, function (WaitingReminder $reminder): bool {
        $payload = $reminder->toArray($this->awaited);

        return $payload['action'] === 'waiting_reminder'
            && $payload['count'] === 2
            && $payload['url'] === route('waiting.index');
    });

    expect($oldest->reference)->not->toBeEmpty();
});
