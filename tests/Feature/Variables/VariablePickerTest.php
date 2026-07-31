<?php

use App\Livewire\Tasks\TaskView;
use App\Livewire\Variables\CreateVariable;
use App\Models\Project;
use App\Models\Task;
use App\Models\Variable;
use App\Queries\MentionSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['short_name' => 'SCI']);
    $this->member = userWithRole($this->project, 'member');
});

it('offers the project variables to the editor picker', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);
    Variable::factory()->for($this->project)->unset()->create(['name' => 'villain']);

    $this->actingAs($this->member);

    $data = app(MentionSuggestions::class)->handle($this->project);

    expect($data['variables'])->toBe([
        ['name' => 'hero', 'value' => 'Robin Hood'],
        ['name' => 'villain', 'value' => null],
    ]);
});

it('tells the picker whether the author may define a new name', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero']);

    $this->actingAs($this->member);
    expect(app(MentionSuggestions::class)->handle($this->project)['can_create_variables'])->toBeTrue();

    $this->actingAs(userWithRole($this->project, 'viewer'));
    expect(app(MentionSuggestions::class)->handle($this->project)['can_create_variables'])->toBeFalse();
});

it('serves the variables over the suggestions endpoint', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero', 'value' => 'Robin Hood']);

    $this->actingAs($this->member)
        ->getJson(route('project.mentionables', $this->project))
        ->assertOk()
        ->assertJsonPath('variables.0.name', 'hero')
        ->assertJsonPath('variables.0.value', 'Robin Hood')
        ->assertJsonPath('can_create_variables', true);
});

it('opens the create dialog with the typed name', function () {
    Livewire::actingAs($this->member)
        ->test(CreateVariable::class, ['shortName' => 'SCI'])
        ->call('open', 'Main_Protagonist')
        ->assertSet('open', true)
        ->assertSet('name', 'main_protagonist');
});

it('creates the variable and tells the editor to insert the usage', function () {
    Livewire::actingAs($this->member)
        ->test(CreateVariable::class, ['shortName' => 'SCI'])
        ->call('open', 'hero')
        ->set('value', 'Robin Hood')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('variable-created', name: 'hero');

    expect($this->project->variables()->first()->value)->toBe('Robin Hood');
});

it('creates a variable with no value yet from the editor', function () {
    Livewire::actingAs($this->member)
        ->test(CreateVariable::class, ['shortName' => 'SCI'])
        ->call('open', 'villain')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->project->variables()->first()->isUnset())->toBeTrue();
});

it('rejects a name the variables page would reject too', function (string $name) {
    Livewire::actingAs($this->member)
        ->test(CreateVariable::class, ['shortName' => 'SCI'])
        ->call('open')
        ->set('name', $name)
        ->call('save')
        ->assertHasErrors('name')
        ->assertNotDispatched('variable-created');
})->with(['1', 'h', 'main protagonist', 'hero!']);

it('rejects a name the project already uses', function () {
    Variable::factory()->for($this->project)->create(['name' => 'hero']);

    Livewire::actingAs($this->member)
        ->test(CreateVariable::class, ['shortName' => 'SCI'])
        ->call('open', 'hero')
        ->call('save')
        ->assertHasErrors('name');

    expect($this->project->variables()->count())->toBe(1);
});

it('keeps the dialog from someone who may not manage variables', function () {
    Livewire::actingAs(userWithRole($this->project, 'viewer'))
        ->test(CreateVariable::class, ['shortName' => 'SCI'])
        ->assertForbidden();
});

it('offers the dialog on the pages that host an editor', function () {
    $task = Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'SCI', 'task_number' => $task->task_number])
        ->assertSeeHtml('data-test="create-variable-modal"');
});

it('leaves the dialog out for someone who may not manage variables', function () {
    $task = Task::factory()->for($this->project)->create();

    Livewire::actingAs(userWithRole($this->project, 'viewer'))
        ->test(TaskView::class, ['short_name' => 'SCI', 'task_number' => $task->task_number])
        ->assertDontSeeHtml('data-test="create-variable-modal"');
});

it('saves content using a name no variable defines, rather than blocking it', function () {
    $task = Task::factory()->for($this->project)->create();

    Livewire::actingAs($this->member)
        ->test(TaskView::class, ['short_name' => 'SCI', 'task_number' => $task->task_number])
        ->call('edit')
        ->set('description', '<p>Enter [sidekick].</p>')
        ->call('save')
        ->assertHasNoErrors();

    expect($task->fresh()->description)->toContain('[sidekick]');
});
