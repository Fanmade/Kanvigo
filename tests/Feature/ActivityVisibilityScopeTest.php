<?php

use App\Models\Activity;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->member = User::factory()->create();
    joinProject($this->project, $this->member);
});

it('shows the activity of the projects the user belongs to', function () {
    $task = Task::factory()->for($this->project)->create();

    // The project's own "created" row belongs to the feed too, so the expected
    // set is everything recorded in the project — not just the task's rows.
    $expected = Activity::query()->where('project_id', $this->project->id)->pluck('id')->all();

    expect($expected)->toContain(...$task->activities()->pluck('id')->all())
        ->and(Activity::query()->visibleTo($this->member)->pluck('id')->all())
        ->toEqualCanonicalizing($expected);
});

it('hides the activity of a project the user is not in', function () {
    $foreign = Project::factory()->create();
    Task::factory()->for($foreign)->create();
    Task::factory()->for($this->project)->create();

    expect(Activity::query()->visibleTo($this->member)->pluck('project_id')->unique()->all())
        ->toBe([$this->project->id])
        ->and(Activity::query()->visibleTo($this->member)->count())
        ->toBe(Activity::query()->where('project_id', $this->project->id)->count());
});

it('does not let a foreign project shorten a page', function () {
    // Interleave foreign rows with the user's own so a filter applied after the
    // fetch would hand back a page with holes in it.
    $foreign = Project::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        Task::factory()->for($this->project)->create();
        Task::factory()->for($foreign)->create();
    }

    $page = Activity::query()->visibleTo($this->member)->latest('id')->limit(4)->get();

    expect($page)->toHaveCount(4)
        ->and($page->pluck('project_id')->unique()->all())->toBe([$this->project->id]);
});

it('hides a doc draft activity from someone who may not edit docs', function () {
    $viewer = userWithRole($this->project, 'viewer');
    $editor = userWithRole($this->project, 'member');

    $draft = Doc::factory()->for($this->project)->create(['is_public' => false]);

    $draftActivityIds = $draft->activities()->pluck('id')->all();

    expect($draftActivityIds)->not->toBeEmpty()
        ->and(Activity::query()->visibleTo($viewer)->pluck('id')->all())
        ->not->toContain(...$draftActivityIds)
        ->and(Activity::query()->visibleTo($editor)->pluck('id')->all())
        ->toContain(...$draftActivityIds);
});

it('shows a published doc activity to everyone who can read the project', function () {
    $viewer = userWithRole($this->project, 'viewer');

    $published = Doc::factory()->for($this->project)->published()->create();

    expect(Activity::query()->visibleTo($viewer)->pluck('id')->all())
        ->toContain(...$published->activities()->pluck('id')->all());
});

it('shows nothing to a user without projects', function () {
    Task::factory()->for($this->project)->create();

    expect(Activity::query()->visibleTo(User::factory()->create())->count())->toBe(0);
});

it('spans every project the user belongs to', function () {
    $other = Project::factory()->create();
    joinProject($other, $this->member);
    Task::factory()->for($other)->create();
    Task::factory()->for($this->project)->create();

    expect(Activity::query()->visibleTo($this->member)->pluck('project_id')->unique()->sort()->values()->all())
        ->toEqualCanonicalizing([$this->project->id, $other->id]);
});
