<?php

use App\Jobs\SyncVariableUsages;
use App\Models\Doc;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\VariableUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'SCI']);
});

/**
 * The names recorded for one item, sorted so assertions read predictably.
 *
 * @return list<string>
 */
function recordedNames(object $item): array
{
    return $item->variableUsages()->orderBy('name')->pluck('name')->all();
}

it('records the names a task description uses', function () {
    $task = Task::factory()->for($this->project)->create([
        'description' => '<p>[hero] meets [villain] again — see note [1].</p>',
    ]);

    expect(recordedNames($task))->toBe(['hero', 'villain']);
    expect(VariableUsage::query()->first()->project_id)->toBe($this->project->id);
});

it('records names for docs, comments and the project description', function () {
    $doc = Doc::factory()->for($this->project)->create(['body' => '<p>[hero] is the lead.</p>']);
    $task = Task::factory()->for($this->project)->create();
    $comment = $task->comments()->create([
        'user_id' => User::factory()->create()->id,
        'body' => '<p>What about [villain]?</p>',
    ]);
    $this->project->update(['description' => '<p>A story about [hero].</p>']);

    expect(recordedNames($doc))->toBe(['hero'])
        ->and(recordedNames($comment))->toBe(['villain'])
        ->and(recordedNames($this->project))->toBe(['hero']);
});

it('ignores names in quoted code, exactly as rendering does', function () {
    $task = Task::factory()->for($this->project)->create([
        'description' => '<p>Set <code>config[hero]</code> and greet [villain].</p>',
    ]);

    expect(recordedNames($task))->toBe(['villain']);
});

it('reconciles the index when the content changes', function () {
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero]</p>']);

    $task->update(['description' => '<p>[villain] only</p>']);

    expect(recordedNames($task))->toBe(['villain']);
});

it('keeps the row of a usage that survives an edit', function () {
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero]</p>']);
    $rowId = $task->variableUsages()->first()->id;

    $task->update(['description' => '<p>[hero] and [villain]</p>']);

    expect($task->variableUsages()->where('name', 'hero')->first()->id)->toBe($rowId);
});

it('drops an item rows when the item is deleted', function () {
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero]</p>']);

    $task->delete();

    expect(VariableUsage::query()->count())->toBe(0);
});

it('drops and restores rows around a soft delete', function () {
    $doc = Doc::factory()->for($this->project)->create(['body' => '<p>[hero]</p>']);

    $doc->delete();
    expect(VariableUsage::query()->count())->toBe(0);

    $doc->restore();
    expect(recordedNames($doc))->toBe(['hero']);
});

it('records a usage of a name no variable defines', function () {
    // Content written before the variable exists is exactly what the index has
    // to represent — that is how an unknown name surfaces.
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[sidekick]</p>']);

    expect(recordedNames($task))->toBe(['sidekick']);
});

it('leaves notes out of the index, since a note has no project namespace', function () {
    Note::factory()->for(User::factory())->create(['body' => '<p>[hero]</p>']);

    expect(VariableUsage::query()->count())->toBe(0);
});

it('syncs on the queue so a save never waits for it', function () {
    Queue::fake();

    Task::factory()->for($this->project)->create(['description' => '<p>[hero]</p>']);

    Queue::assertPushed(SyncVariableUsages::class);
    expect(VariableUsage::query()->count())->toBe(0);
});

it('does not dispatch a sync when the content did not change', function () {
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero]</p>']);

    Queue::fake();
    $task->update(['title' => 'A new title']);

    Queue::assertNothingPushed();
});

it('rebuilds the index from content', function () {
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero]</p>']);
    $doc = Doc::factory()->for($this->project)->create(['body' => '<p>[villain]</p>']);

    // Simulate a queue outage: the rows never arrived.
    VariableUsage::query()->delete();

    $this->artisan('variables:reindex')->assertSuccessful();

    expect(recordedNames($task))->toBe(['hero'])
        ->and(recordedNames($doc))->toBe(['villain']);
});

it('rebuilds one project without touching another', function () {
    $other = Project::factory()->create(['short_name' => 'OTH']);
    $mine = Task::factory()->for($this->project)->create(['description' => '<p>[hero]</p>']);
    $theirs = Task::factory()->for($other)->create(['description' => '<p>[villain]</p>']);

    VariableUsage::query()->delete();

    $this->artisan('variables:reindex', ['--project' => 'SCI'])->assertSuccessful();

    expect(recordedNames($mine))->toBe(['hero'])
        ->and(recordedNames($theirs))->toBe([]);
});

it('reports an unknown project short name', function () {
    $this->artisan('variables:reindex', ['--project' => 'NOPE'])->assertFailed();
});

it('is safe to rebuild twice', function () {
    Task::factory()->for($this->project)->create(['description' => '<p>[hero] and [hero]</p>']);

    $this->artisan('variables:reindex')->assertSuccessful();
    $this->artisan('variables:reindex')->assertSuccessful();

    expect(VariableUsage::query()->count())->toBe(1);
});
