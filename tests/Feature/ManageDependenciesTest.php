<?php

use App\Enums\Status;
use App\Livewire\Stories\StoryView;
use App\Livewire\Tasks\TaskView;
use App\Models\Dependency;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    $this->project->members()->attach($this->member);
    $this->story = Story::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->story)->status(Status::Planned)->create();
    $this->other = Task::factory()->for($this->story)->status(Status::ToDo)->create();

    $this->mountTask = fn () => Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'story_number' => $this->story->story_number,
            'task_number' => $this->task->task_number,
        ]);
});

it('adds a blocked-by dependency by reference', function () {
    ($this->mountTask)()
        ->set('dependencyDirection', 'blocked_by')
        ->set('dependencyReference', $this->other->reference)
        ->call('addDependency')
        ->assertHasNoErrors();

    expect($this->task->fresh()->blockers()->pluck('id'))->toContain($this->other->id);
});

it('adds a blocks dependency by reference', function () {
    ($this->mountTask)()
        ->set('dependencyDirection', 'blocks')
        ->set('dependencyReference', $this->other->reference)
        ->call('addDependency')
        ->assertHasNoErrors();

    expect($this->task->fresh()->blocking()->pluck('id'))->toContain($this->other->id);
});

it('records a dependency activity when a link is added', function () {
    ($this->mountTask)()
        ->set('dependencyReference', $this->other->reference)
        ->call('addDependency');

    expect($this->task->activities()->where('action', 'dependency_changed')->exists())->toBeTrue();
});

it('offers same-project stories and tasks as candidates, searchable by reference and title, excluding itself', function () {
    $this->other->update(['title' => 'Database migration']);
    $this->story->update(['title' => 'Backend setup']);

    $candidates = ($this->mountTask)()->instance()->dependencyCandidates;

    $references = $candidates->pluck('reference')->all();

    expect($references)->toContain($this->other->reference)
        ->toContain($this->story->reference)
        ->not->toContain($this->task->reference);

    // The label combines reference and title so the combobox can match either.
    $otherLabel = $candidates->firstWhere('reference', $this->other->reference)['label'];
    expect($otherLabel)->toContain($this->other->reference)->toContain('Database migration');
});

it('does not offer items from other projects as candidates', function () {
    $hidden = Task::factory()->for(Story::factory()->for(Project::factory()))->create();

    $candidates = ($this->mountTask)()->instance()->dependencyCandidates;

    expect($candidates->pluck('reference')->all())->not->toContain($hidden->reference);
});

it('rejects an unknown reference', function () {
    ($this->mountTask)()
        ->set('dependencyReference', 'ZZZ9-9')
        ->call('addDependency')
        ->assertHasErrors('dependencyReference');

    expect($this->task->fresh()->blockers())->toHaveCount(0);
});

it('rejects a self-dependency', function () {
    ($this->mountTask)()
        ->set('dependencyReference', $this->task->reference)
        ->call('addDependency')
        ->assertHasErrors('dependencyReference');
});

it('rejects a dependency that would create a cycle', function () {
    // other is already blocked by task, so blocking task with other closes a loop.
    $this->other->addBlocker($this->task);

    ($this->mountTask)()
        ->set('dependencyDirection', 'blocked_by')
        ->set('dependencyReference', $this->other->reference)
        ->call('addDependency')
        ->assertHasErrors('dependencyReference');
});

it('rejects an item the user cannot access', function () {
    $hidden = Task::factory()->for(Story::factory()->for(Project::factory()))->create();

    ($this->mountTask)()
        ->set('dependencyReference', $hidden->reference)
        ->call('addDependency')
        ->assertHasErrors('dependencyReference');
});

it('removes a dependency', function () {
    $this->task->addBlocker($this->other);
    $link = Dependency::firstOrFail();

    ($this->mountTask)()
        ->call('removeDependency', $link->id)
        ->assertHasNoErrors();

    expect($this->task->fresh()->blockers())->toHaveCount(0);
});

it('shows the blocked badge while a blocker is unfinished', function () {
    $this->task->addBlocker($this->other);

    ($this->mountTask)()
        ->assertSee(__('Blocked'));
});

it('manages dependencies on a story view too', function () {
    Livewire::actingAs($this->member)
        ->test(StoryView::class, ['short_name' => 'ABC', 'story_number' => $this->story->story_number])
        ->set('dependencyReference', $this->other->reference)
        ->call('addDependency')
        ->assertHasNoErrors();

    expect($this->story->fresh()->blockers()->pluck('id'))->toContain($this->other->id);
});

it('does not let a non-member manage dependencies', function () {
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'story_number' => $this->story->story_number,
            'task_number' => $this->task->task_number,
        ])
        ->assertForbidden();
});
