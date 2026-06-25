<?php

use App\Enums\Status;
use App\Livewire\Tasks\TaskView;
use App\Models\Dependency;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->member = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($this->project, $this->member);
    $this->parent = Task::factory()->for($this->project)->create();
    $this->task = Task::factory()->for($this->project)->childOf($this->parent)->status(Status::Planned)->create();
    $this->other = Task::factory()->for($this->project)->childOf($this->parent)->status(Status::ToDo)->create();

    $this->mountTask = fn () => Livewire::actingAs($this->member)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
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

it('records a dependency activity capturing the direction and related reference', function () {
    ($this->mountTask)()
        ->set('dependencyDirection', 'blocked_by')
        ->set('dependencyReference', $this->other->reference)
        ->call('addDependency');

    $activity = $this->task->activities()->where('action', 'dependency_changed')->first();

    expect($activity)->not->toBeNull()
        ->and(json_decode((string) $activity->new_value, true))
        ->toBe(['direction' => 'blocked_by', 'reference' => $this->other->reference])
        ->and($activity->old_value)->toBeNull();
});

it('offers no candidates until a search term is entered', function () {
    expect(($this->mountTask)()->instance()->dependencyCandidates)->toBeEmpty();
});

it('offers same-project tasks as candidates, searchable by reference and title, excluding itself', function () {
    $this->other->update(['title' => 'Database migration']);

    $component = ($this->mountTask)();

    // Searchable by a title substring...
    $byTitle = $component->set('dependencyReference', 'Database')->instance()->dependencyCandidates;
    expect($byTitle->pluck('reference')->all())->toContain($this->other->reference);

    // ...the label combines reference and title.
    $label = $byTitle->firstWhere('reference', $this->other->reference)['label'];
    expect($label)->toContain($this->other->reference)->toContain('Database migration');

    // ...and by the task number.
    $byNumber = $component->set('dependencyReference', (string) $this->other->task_number)->instance()->dependencyCandidates;
    expect($byNumber->pluck('reference')->all())->toContain($this->other->reference);

    // Never offers the viewed task itself, even when its own title matches.
    $this->task->update(['title' => 'Database cleanup']);
    $self = $component->set('dependencyReference', 'Database')->instance()->dependencyCandidates;
    expect($self->pluck('reference')->all())->not->toContain($this->task->reference);
});

it('does not offer items from other projects as candidates', function () {
    $hidden = Task::factory()->for(Project::factory())->create(['title' => 'Hidden cross-project task']);

    $candidates = ($this->mountTask)()
        ->set('dependencyReference', 'Hidden cross-project')
        ->instance()->dependencyCandidates;

    expect($candidates->pluck('reference')->all())->not->toContain($hidden->reference);
});

it('caps the candidate list so the picker query stays bounded', function () {
    Task::factory()->count(15)->for($this->project)->create(['title' => 'Searchable widget']);

    $candidates = ($this->mountTask)()
        ->set('dependencyReference', 'Searchable widget')
        ->instance()->dependencyCandidates;

    expect($candidates->count())->toBeLessThanOrEqual(10);
});

it('rejects an unknown reference', function () {
    ($this->mountTask)()
        ->set('dependencyReference', 'ZZZ-99')
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
    $hidden = Task::factory()->for(Project::factory())->create();

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

    $activity = $this->task->activities()->where('action', 'dependency_changed')->first();

    expect($this->task->fresh()->blockers())->toHaveCount(0)
        ->and(json_decode((string) $activity->old_value, true))
        ->toBe(['direction' => 'blocked_by', 'reference' => $this->other->reference])
        ->and($activity->new_value)->toBeNull();
});

it('shows the blocked badge while a blocker is unfinished', function () {
    $this->task->addBlocker($this->other);

    ($this->mountTask)()
        ->assertSee(__('Blocked'));
});

it('manages dependencies on a parent task view too', function () {
    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'ABC', 'task_number' => $this->parent->task_number])
        ->set('dependencyReference', $this->other->reference)
        ->call('addDependency')
        ->assertHasNoErrors();

    expect($this->parent->fresh()->blockers()->pluck('id'))->toContain($this->other->id);
});

it('does not let a non-member manage dependencies', function () {
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test(TaskView::class, [
            'short_name' => 'ABC',
            'task_number' => $this->task->task_number,
        ])
        ->assertForbidden();
});
