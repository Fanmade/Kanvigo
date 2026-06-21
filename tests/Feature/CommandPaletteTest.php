<?php

use App\Livewire\CommandPalette;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Acme Board']);
    $this->project->members()->attach($this->user);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Deploy fix']);
});

it('finds a task by its title', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'Deploy')
        ->assertSee('Deploy fix');
});

it('finds a project by its short name', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'ABC')
        ->assertSee('Acme Board');
});

it('matches titles case-insensitively regardless of query case', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'deploy') // stored as "Deploy fix"
        ->assertSee('Deploy fix')
        ->set('query', 'ACME') // stored as "Acme Board"
        ->assertSee('Acme Board');
});

it('finds a task by its tag', function () {
    $this->task->syncTags('urgent');

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'urgent')
        ->assertSee('Deploy fix');
});

it('does not attach progress to task or project results', function () {
    $project = app(GlobalSearch::class)->search($this->user, 'ABC')->firstWhere('type', 'project');
    $task = app(GlobalSearch::class)->search($this->user, 'Deploy')->firstWhere('type', 'task');

    expect($project->progress)->toBeNull()
        ->and($task->progress)->toBeNull();
});

it('pins a jump result for a typed reference', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', $this->task->reference)
        ->assertSee('Deploy fix')
        ->assertSee($this->task->reference);
});

it('does not surface items from projects the user cannot access', function () {
    $otherProject = Project::factory()->create(['short_name' => 'XYZ']);
    Task::factory()->for($otherProject)->create(['title' => 'Secret task']);

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'Secret')
        ->assertDontSee('Secret task');
});

it('does not jump to a reference the user cannot access', function () {
    $otherProject = Project::factory()->create(['short_name' => 'XYZ']);
    $otherTask = Task::factory()->for($otherProject)->create(['title' => 'Secret task']);

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', $otherTask->reference)
        ->assertDontSee('Secret task');
});

it('shows the quick actions immediately, before any query', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->assertSee('Dashboard')
        ->assertSee('Projects')
        ->assertSee('Board')
        ->assertSee('Notifications');
});

it('shows the New project action only to permitted users', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->assertDontSee('New project');

    $creator = User::factory()->canCreateProjects()->create();

    Livewire::actingAs($creator)
        ->test(CommandPalette::class)
        ->assertSee('New project');
});

it('deep-links the New project action to the open create form', function () {
    $creator = User::factory()->canCreateProjects()->create();

    $action = Livewire::actingAs($creator)
        ->test(CommandPalette::class)
        ->instance()
        ->actions()
        ->firstWhere('title', 'New project');

    expect($action->url)->toContain('create=1');
});

it('clears its query when closed', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('query', 'Deploy')
        ->call('close')
        ->assertSet('query', '');
});

it('navigates to the selected entry', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->call('go', route('dashboard'))
        ->assertRedirect(route('dashboard'));
});
