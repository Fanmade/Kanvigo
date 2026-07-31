<?php

use App\Livewire\Projects\ProjectVariables;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'SCI']);
    $this->member = userWithRole($this->project, 'member');
});

/**
 * The variables page, acting as a member who may manage variables.
 */
function variablesPage(): Testable
{
    return Livewire::actingAs(test()->member)
        ->test(ProjectVariables::class, ['short_name' => 'SCI']);
}

it('lists the project variables with their values and descriptions', function () {
    Variable::factory()->for($this->project)->create([
        'name' => 'hero',
        'value' => 'Robin Hood',
        'description' => 'The protagonist',
    ]);
    Variable::factory()->for($this->project)->unset()->create(['name' => 'villain']);

    variablesPage()
        ->assertOk()
        ->assertSeeText('[hero]')
        ->assertSeeText('Robin Hood')
        ->assertSeeText('The protagonist')
        ->assertSeeText('[villain]')
        ->assertSeeText('No value yet');
});

it('shows how often each variable is used', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    Variable::factory()->for($this->project)->create(['name' => 'villain', 'value' => 'The Sheriff']);
    Task::factory()->for($this->project)->create(['description' => '<p>[hero] arrives.</p>']);
    Task::factory()->for($this->project)->create(['description' => '<p>[hero] leaves.</p>']);

    variablesPage()
        ->assertSeeText('2 uses')
        ->assertSeeText('Unused');
});

it('surfaces names used in content that no variable defines', function () {
    Task::factory()->for($this->project)->create(['description' => '<p>[sidekick] waits.</p>']);

    variablesPage()
        ->assertSeeHtml('data-test="unknown-names"')
        ->assertSeeText('[sidekick]');
});

it('offers to define an unknown name with it pre-filled', function () {
    Task::factory()->for($this->project)->create(['description' => '<p>[sidekick] waits.</p>']);

    variablesPage()
        ->call('startCreate', 'sidekick')
        ->assertSet('editName', 'sidekick')
        ->set('editValue', 'Little John')
        ->call('save')
        ->assertHasNoErrors()
        // Defining it moves the name out of the unknown list.
        ->assertDontSeeHtml('data-test="unknown-names"');
});

it('leaves the unknown list out when every used name is defined', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    Task::factory()->for($this->project)->create(['description' => '<p>[hero] arrives.</p>']);

    variablesPage()->assertDontSeeHtml('data-test="unknown-names"');
});

it('creates a variable', function () {
    variablesPage()
        ->call('startCreate')
        ->set('editName', 'Main_Protagonist')
        ->set('editValue', 'Robin Hood')
        ->set('editDescription', 'The protagonist')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', false);

    expect($this->project->variables()->first())
        ->name->toBe('main_protagonist')
        ->value->toBe('Robin Hood')
        ->description->toBe('The protagonist');
});

it('creates a variable with no value yet', function () {
    variablesPage()
        ->call('startCreate')
        ->set('editName', 'villain')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->project->variables()->first()->isUnset())->toBeTrue();
});

it('rejects a name that is not a valid variable name', function (string $name) {
    variablesPage()
        ->call('startCreate')
        ->set('editName', $name)
        ->call('save')
        ->assertHasErrors('editName');

    expect($this->project->variables()->count())->toBe(0);
})->with(['1', 'h', 'main protagonist', '_hero', 'hero!']);

it('rejects a name already used in the project', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero']);

    variablesPage()
        ->call('startCreate')
        ->set('editName', 'HERO')
        ->call('save')
        ->assertHasErrors('editName');

    expect($this->project->variables()->count())->toBe(1);
});

it('accepts a name another project already uses', function () {
    $other = Project::factory()->create(['short_name' => 'OTH']);
    Variable::factory()->for($other)->create(['name' => 'hero']);

    variablesPage()
        ->call('startCreate')
        ->set('editName', 'hero')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->project->variables()->count())->toBe(1);
});

it('edits a variable without tripping over its own name', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    variablesPage()
        ->call('startEdit', $variable->id)
        ->assertSet('editName', 'hero')
        ->assertSet('editValue', 'Robin Hood')
        ->set('editValue', 'Robin of Loxley')
        ->call('save')
        ->assertHasNoErrors();

    expect($variable->fresh()->value)->toBe('Robin of Loxley');
});

it('clears a value back to unset', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    variablesPage()
        ->call('startEdit', $variable->id)
        ->set('editValue', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($variable->fresh()->isUnset())->toBeTrue();
});

it('deletes a variable without touching any content', function () {
    $variable = Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    $task = Task::factory()->for($this->project)->create(['description' => '<p>[hero] arrives.</p>']);

    variablesPage()->call('deleteVariable', $variable->id);

    expect(Variable::query()->count())->toBe(0)
        ->and($task->fresh()->description)->toContain('[hero]');
});

it('refuses to touch a variable from another project', function () {
    $other = Project::factory()->create(['short_name' => 'OTH']);
    $foreign = Variable::factory()->for($other)->create(['name' => 'hero']);

    expect(fn () => variablesPage()->call('deleteVariable', $foreign->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(Variable::query()->count())->toBe(1);
});

it('keeps the page from members who cannot manage variables', function () {
    Livewire::actingAs(userWithRole($this->project, 'viewer'))
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->assertForbidden();
});

it('keeps the page from non-members', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ProjectVariables::class, ['short_name' => 'SCI'])
        ->assertForbidden();
});

it('re-authorizes on every request, not just on mount', function () {
    $page = variablesPage();

    // Tamper with the locked short name the way a crafted request would.
    $component = $page->instance();
    (fn () => $this->shortName = 'OTH')->call($component);

    Project::factory()->create(['short_name' => 'OTH']);

    expect(fn () => $component->project())->toThrow(AuthorizationException::class);
});
