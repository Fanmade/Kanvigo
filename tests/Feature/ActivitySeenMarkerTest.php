<?php

use App\Livewire\Activity\GlobalActivityFeed;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->other = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, [$this->member->id, $this->other->id]);

    // Setting the project up is the reader's own doing, so the row it records
    // never counts as something they missed. Attributing it keeps the counts in
    // these tests about the activity each case actually creates.
    Activity::query()->update(['user_id' => $this->member->id]);
});

/**
 * Record activity in the shared project, attributed to the other member and
 * stamped at the given time.
 */
function activityAt(Project $project, User $actor, string $at): Activity
{
    Carbon::setTestNow($at);

    $task = Task::factory()->for($project)->create();
    $activity = $task->activities()->sole();
    $activity->forceFill(['user_id' => $actor->id])->save();

    Carbon::setTestNow();

    return $activity;
}

it('marks the visit but still shows what was new on that very render', function () {
    $this->member->forceFill(['activities_seen_at' => Carbon::parse('2026-08-10 12:00')])->save();

    $old = activityAt($this->project, $this->other, '2026-08-09 09:00');
    activityAt($this->project, $this->other, '2026-08-11 09:00');

    $component = Livewire::actingAs($this->member)->test(GlobalActivityFeed::class);

    // The divider sits above the first row the reader had already seen …
    expect($component->instance()->firstSeenId())->toBe($old->id)
        // … while the stored timestamp has already moved on for the next visit.
        ->and($this->member->fresh()->activities_seen_at->isToday())->toBeTrue();
});

it('draws no divider when the page holds nothing new', function () {
    activityAt($this->project, $this->other, '2026-08-09 09:00');
    $this->member->forceFill(['activities_seen_at' => Carbon::parse('2026-08-10 12:00')])->save();

    $component = Livewire::actingAs($this->member)->test(GlobalActivityFeed::class);

    expect($component->instance()->firstSeenId())->toBeNull();
    $component->assertDontSeeHtml('data-test="new-since-divider"');
});

it('draws no divider on a first visit', function () {
    activityAt($this->project, $this->other, '2026-08-09 09:00');

    expect($this->member->activities_seen_at)->toBeNull();

    $component = Livewire::actingAs($this->member)->test(GlobalActivityFeed::class);

    // Everything is new to a first-time reader, so nothing is marked as new —
    // and the visit is recorded, so the next one has a line to draw.
    expect($component->instance()->firstSeenId())->toBeNull()
        ->and($this->member->fresh()->activities_seen_at)->not->toBeNull();
});

it('keeps the divider in place while paging and filtering', function () {
    $this->member->forceFill(['activities_seen_at' => Carbon::parse('2026-08-10 12:00')])->save();

    $old = activityAt($this->project, $this->other, '2026-08-09 09:00');
    activityAt($this->project, $this->other, '2026-08-11 09:00');

    $component = Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->set('category', 'progress');

    // The seen-at property is held, not re-read, so a second interaction does
    // not silently mark everything as old.
    expect($component->instance()->firstSeenId())->toBe($old->id);
});

it('renders the divider row in the list', function () {
    $this->member->forceFill(['activities_seen_at' => Carbon::parse('2026-08-10 12:00')])->save();

    activityAt($this->project, $this->other, '2026-08-09 09:00');
    activityAt($this->project, $this->other, '2026-08-11 09:00');

    Livewire::actingAs($this->member)
        ->test(GlobalActivityFeed::class)
        ->assertSeeHtml('data-test="new-since-divider"')
        ->assertSee(__('New since your last visit'));
});

it('counts only unseen activity by other people, within the visible projects', function () {
    $foreign = Project::factory()->create();
    $outsider = User::factory()->create();
    joinProject($foreign, $outsider);

    $this->member->forceFill(['activities_seen_at' => Carbon::parse('2026-08-10 12:00')])->save();

    activityAt($this->project, $this->other, '2026-08-09 09:00');   // seen already
    activityAt($this->project, $this->other, '2026-08-11 09:00');   // new
    activityAt($this->project, $this->member, '2026-08-11 10:00');  // the reader's own
    activityAt($foreign, $outsider, '2026-08-11 11:00');            // not visible

    expect($this->member->fresh()->unseenActivityCount())->toBe(1);
});

it('counts everything visible when the feed has never been opened', function () {
    activityAt($this->project, $this->other, '2026-08-09 09:00');
    activityAt($this->project, $this->other, '2026-08-11 09:00');

    expect($this->member->fresh()->unseenActivityCount())->toBe(2);
});

it('drops to zero once the feed is opened', function () {
    activityAt($this->project, $this->other, '2026-08-11 09:00');

    expect($this->member->fresh()->unseenActivityCount())->toBe(1);

    Livewire::actingAs($this->member)->test(GlobalActivityFeed::class);

    // A fresh instance: the count is cached per user and seen-at stamp, so
    // stamping the visit selects a different key rather than a stale one.
    expect($this->member->fresh()->unseenActivityCount())->toBe(0);
});

it('counts without loading the rows', function () {
    activityAt($this->project, $this->other, '2026-08-11 09:00');

    Cache::flush();

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->member->fresh()->unseenActivityCount();

    $activityQueries = collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'from "activities"'));

    expect($activityQueries)->not->toBeEmpty();

    foreach ($activityQueries as $sql) {
        expect($sql)->toContain('count(*)');
    }
});
