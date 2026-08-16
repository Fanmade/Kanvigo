<?php

use App\Enums\Priority;
use App\Livewire\Comments\CommentList;
use App\Models\Activity;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use App\Support\Facades\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create();
    joinProject($this->project, $this->member);
});

it('stamps the owning project on a task activity', function () {
    $task = Task::factory()->for($this->project)->create();

    expect($task->activities()->pluck('project_id')->unique()->all())->toBe([$this->project->id]);
});

it('stamps the project on its own activities', function () {
    $this->project->update(['title' => 'Renamed']);

    expect($this->project->activities()->pluck('project_id')->unique()->all())->toBe([$this->project->id]);
});

it('stamps the project on doc and variable activities', function () {
    $doc = Doc::factory()->for($this->project)->create();
    $variable = Variable::factory()->for($this->project)->create();

    expect($doc->activities()->pluck('project_id')->unique()->all())->toBe([$this->project->id])
        ->and($variable->activities()->pluck('project_id')->unique()->all())->toBe([$this->project->id]);
});

it('stamps the project on a comment activity, which is recorded on the task', function () {
    $task = Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $task])
        ->set('body', '<p>Hi</p>')
        ->call('addComment')
        ->assertHasNoErrors();

    expect($task->activities()->where('action', 'commented')->pluck('project_id')->all())
        ->toBe([$this->project->id]);
});

it('still records the row when the subject is deleted before the sink writes it', function () {
    $task = Task::factory()->for($this->project)->create();
    $activityCount = Activity::query()->count();

    // The event carries the project, so a subject that disappears between the
    // emit and the write still yields a project-scoped row.
    $event = $task->contentAuditEvent('status_changed', 'status', 'Planned', 'Done');
    $task->forceDelete();

    Audit::record($event);

    expect(Activity::query()->count())->toBe($activityCount + 1)
        ->and(Activity::query()->latest('id')->first()->project_id)->toBe($this->project->id);
});

it('leaves no activity without a project across a mixed run of changes', function () {
    // The column stays nullable for rows whose subject is gone, so this is the
    // guard that every newly written row still carries its project.
    $this->actingAs($this->member);

    $task = Task::factory()->for($this->project)->create();
    $task->update(['title' => 'Renamed', 'priority' => Priority::High]);
    $task->assignees()->sync([$this->member->id]);
    $task->recordAssigneeChange([$this->member->id], []);
    $this->project->update(['title' => 'Also renamed']);
    Doc::factory()->for($this->project)->create();
    Variable::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(CommentList::class, ['commentable' => $task])
        ->set('body', '<p>Hi</p>')
        ->call('addComment');

    expect(Activity::query()->count())->toBeGreaterThan(5)
        ->and(Activity::query()->whereNull('project_id')->count())->toBe(0);
});

it('backfills rows written before the column existed', function () {
    // Push the project ids away from the activity ids: when the two ranges
    // overlap, a mapping that pairs the wrong columns still looks right.
    Project::factory()->count(5)->create();
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();
    $doc = Doc::factory()->for($project)->create();
    $project->update(['title' => 'Renamed']);

    // Simulate the pre-migration state.
    DB::table('activities')->update(['project_id' => null]);

    $this->artisan('activities:backfill-projects')->assertSuccessful();

    expect(Activity::query()->whereNull('project_id')->count())->toBe(0)
        ->and($task->activities()->pluck('project_id')->unique()->all())->toBe([$project->id])
        ->and($doc->activities()->pluck('project_id')->unique()->all())->toBe([$project->id])
        // A project's own rows are stamped with itself, not with a neighbour.
        ->and($project->activities()->pluck('project_id')->unique()->all())->toBe([$project->id]);
});

it('reports rows it cannot resolve instead of guessing', function () {
    $task = Task::factory()->for($this->project)->create();

    DB::table('activities')->update(['project_id' => null]);
    DB::table('activities')->where('subject_type', Task::class)->update(['subject_id' => 999999]);

    $this->artisan('activities:backfill-projects')
        ->expectsOutputToContain('their subject no longer exists')
        ->assertSuccessful();

    expect(Activity::query()->whereNull('project_id')->count())->toBeGreaterThan(0)
        ->and($task->fresh())->not->toBeNull();
});
