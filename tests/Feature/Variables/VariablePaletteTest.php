<?php

use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use App\Support\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'SCI']);
    $this->member = userWithRole($this->project, 'member');
});

/**
 * The references the palette returns for a query, run as the member by default.
 *
 * @return Collection<int, string|null>
 */
function paletteReferences(string $query, ?User $user = null): Collection
{
    return app(GlobalSearch::class)->search($user ?? test()->member, $query)->pluck('reference');
}

/**
 * The result types the palette returns for a query.
 *
 * @return Collection<int, string>
 */
function paletteTypes(string $query, ?User $user = null): Collection
{
    return app(GlobalSearch::class)->search($user ?? test()->member, $query)->pluck('type');
}

it('finds a variable in the command palette by name or value', function () {
    Variable::factory()->for($this->project)->create(['name' => 'main_protagonist', 'value' => 'Robin Hood']);

    $byName = app(GlobalSearch::class)->search($this->member, 'protagonist');
    $byValue = app(GlobalSearch::class)->search($this->member, 'robin');

    expect($byName->pluck('reference'))->toContain('[main_protagonist]')
        ->and($byValue->pluck('reference'))->toContain('[main_protagonist]')
        ->and($byValue->firstWhere('type', 'variable')->title)->toBe('Robin Hood')
        ->and($byValue->firstWhere('type', 'variable')->url)->toContain('/SCI/variables#variable-main_protagonist');
});

it('keeps variables out of the palette for someone who may not manage them', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $viewer = userWithRole($this->project, 'viewer');

    $results = app(GlobalSearch::class)->search($viewer, 'robin');

    expect($results->pluck('type'))->not->toContain('variable');
});

it('keeps variables of other projects out of the palette', function () {
    $other = Project::factory()->create(['short_name' => 'OTH']);
    Variable::factory()->for($other)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    expect(paletteTypes('robin'))->not->toContain('variable');
});

it('finds the pages that use a matched variable', function () {
    Variable::factory()->for($this->project)->create(['name' => 'main_protagonist', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create([
        'title' => 'Scene one',
        'description' => '<p>[main_protagonist] arrives.</p>',
    ]);
    $doc = Doc::factory()->for($this->project)->published()->create([
        'title' => 'Cast',
        'body' => '<p>Our lead is [main_protagonist].</p>',
    ]);

    // The stored text says "[main_protagonist]", never "Robin Hood", so this can
    // only work through the usage index.
    expect(paletteReferences('robin'))
        ->toContain('[main_protagonist]')
        ->toContain($task->reference)
        ->toContain($doc->reference);
});

it('reaches the page a comment was written on', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create(['title' => 'Scene two']);
    $task->comments()->create(['user_id' => $this->member->id, 'body' => '<p>What about [hero]?</p>']);

    // A comment has no page of its own; the task it was written on is the result.
    expect(paletteReferences('robin'))->toContain($task->reference);
});

it('leaves out a page the searcher may not view', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $draft = Doc::factory()->for($this->project)->create(['body' => '<p>[hero]</p>']);

    $viewer = userWithRole($this->project, 'viewer');

    expect(paletteReferences('robin', $viewer))->not->toContain($draft->reference);
});

it('finds usages by the variable name as well as its value', function () {
    Variable::factory()->for($this->project)->create(['name' => 'main_protagonist', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[main_protagonist]</p>']);

    expect(paletteReferences('protagonist'))->toContain($task->reference);
});

it('does not pull in usages when no variable matches', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create(['title' => 'Scene one', 'description' => '<p>[hero]</p>']);

    expect(paletteReferences('sheriff'))->not->toContain($task->reference);
});
