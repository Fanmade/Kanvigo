<?php

use App\Livewire\Activity\ActivityFeed;
use App\Livewire\Projects\ProjectVariables;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'SCI']);
    $this->member = userWithRole($this->project, 'member');
});

/**
 * The recorded actions for a subject, newest first.
 *
 * @return list<string>
 */
function recordedActions(object $subject): array
{
    return $subject->activities()->pluck('action')->all();
}

it('records the creation of a variable on the variable itself', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    expect(recordedActions($variable))->toBe(['variable_created'])
        ->and($variable->activities()->first()->new_value)->toBe('hero');
});

it('records a value change with what it was and what it became', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $variable->update(['value' => 'Robin of Loxley']);

    $entry = $variable->activities()->where('action', 'variable_value_changed')->first();

    // "The hero was Robin Hood until Tuesday" is the whole point of the entry.
    expect($entry)->not->toBeNull()
        ->and($entry->old_value)->toBe('Robin Hood')
        ->and($entry->new_value)->toBe('Robin of Loxley');
});

it('records a rename separately from a value change', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $variable->update(['name' => 'lead', 'value' => 'Robin of Loxley']);

    expect(recordedActions($variable))
        ->toContain('variable_renamed')
        ->toContain('variable_value_changed');
});

it('records nothing when a save changes nothing', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $variable->update(['description' => null]);

    expect(recordedActions($variable))->toBe(['variable_created']);
});

it('records a deletion against the project, so the entry outlives the variable', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $variable->delete();

    $entry = $this->project->activities()->where('action', 'variable_deleted')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->old_value)->toBe('hero');
});

it('records one entry per change, never one per document that uses it', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $tasks = Task::factory()->count(3)->for($this->project)
        ->create(['description' => '<p>[hero] arrives.</p>']);

    $variable = $this->project->variables()->first();
    $variable->update(['value' => 'Robin of Loxley']);

    // The documents were not edited; claiming they were would corrupt their
    // history and reorder every recently-updated list in the project.
    expect(Activity::query()->where('action', 'variable_value_changed')->count())->toBe(1);

    foreach ($tasks as $task) {
        expect($task->fresh()->activities()->where('action', 'description_changed')->count())->toBe(0);
    }
});

it('notifies nobody about a variable change', function () {
    Notification::fake();

    $this->project->subscribers()->syncWithoutDetaching([$this->member->id]);

    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood'])
        ->update(['value' => 'Robin of Loxley']);

    Notification::assertNothingSent();
});

it('shows variable entries in the project activity feed', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    Livewire::actingAs($this->member)
        ->test(ActivityFeed::class, ['subject' => $this->project])
        ->call('toggleCollapsed')
        ->assertSeeText('created the variable hero');
});

it('shows a variable own history on the variables page', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $variable->update(['value' => 'Robin of Loxley']);

    Livewire::actingAs($this->member)
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->call('showHistory', $variable->id)
        ->assertSet('showingHistory', true)
        ->assertSeeText('changed the value from Robin Hood to Robin of Loxley')
        ->assertSeeText('created the variable hero');
});
