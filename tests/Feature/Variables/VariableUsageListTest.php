<?php

use App\Livewire\Projects\ProjectVariables;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'SCI']);
    $this->member = userWithRole($this->project, 'member');
});

/**
 * Open the details of one name on the variables page.
 */
function inspectName(string $name, ?User $user = null): Testable
{
    return Livewire::actingAs($user ?? test()->member)
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->call('inspect', $name);
}

it('lists the pages whose text uses a variable', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create(['title' => 'Scene one', 'description' => '<p>[hero] arrives.</p>']);
    $doc = Doc::factory()->for($this->project)->published()->create(['title' => 'Cast', 'body' => '<p>Meet [hero].</p>']);

    inspectName('hero')
        ->assertSet('inspecting', true)
        ->assertSeeText($task->reference)
        ->assertSeeText('Scene one')
        ->assertSeeText($doc->reference)
        ->assertSeeText('Cast');
});

it('lists the project itself when its description uses the name', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $this->project->update(['description' => '<p>A story about [hero].</p>']);

    inspectName('hero')->assertSeeText('SCI');
});

it('links a comment usage to the page it was written on', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create(['title' => 'Scene two']);
    $task->comments()->create(['user_id' => $this->member->id, 'body' => '<p>What about [hero]?</p>']);

    // A comment has no page of its own.
    inspectName('hero')->assertSeeText($task->reference);
});

it('lists a page once however often its text names the variable', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero] and [hero] again.</p>']);
    $task->comments()->create(['user_id' => $this->member->id, 'body' => '<p>Also [hero].</p>']);

    $usages = inspectName('hero')->instance()->usages();

    expect($usages)->toHaveCount(1);
});

it('leaves out a page the viewer may not see', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $draft = Doc::factory()->for($this->project)->create(['title' => 'Secret cast', 'body' => '<p>[hero]</p>']);

    // Someone who may manage variables but not edit docs never sees a draft, so
    // the list must not disclose one either.
    $variablesOnly = userWithPermissions($this->project, ['manage-variables']);

    inspectName('hero', $variablesOnly)->assertDontSeeText($draft->reference);

    // The editor does see it — the filter is per viewer, not a blanket exclusion.
    inspectName('hero')->assertSeeText($draft->reference);
});

it('lists the usages of a name no variable defines', function () {
    $task = Task::factory()->for($this->project)->create(['title' => 'Scene one', 'description' => '<p>[sidekick] waits.</p>']);

    // An unknown name is exactly what needs finding, so it gets the same list.
    inspectName('sidekick')
        ->assertSeeText($task->reference)
        ->assertSeeText('Scene one');
});

it('says plainly when nothing uses the name', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    inspectName('hero')->assertSeeHtml('data-test="usages-empty"');
});

it('does not claim the list is complete the moment it is read', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    // The index is maintained asynchronously, so the dialog says so.
    inspectName('hero')->assertSeeText('may not be listed yet');
});

it('leaves usages in other projects out', function () {
    $other = Project::factory()->create(['short_name' => 'OTH']);
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $theirs = Task::factory()->for($other)->create(['description' => '<p>[hero] elsewhere.</p>']);

    inspectName('hero')->assertDontSeeText($theirs->reference);
});

it('keeps the details from someone who may not manage variables', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero']);

    Livewire::actingAs(userWithRole($this->project, 'viewer'))
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->assertForbidden();
});
