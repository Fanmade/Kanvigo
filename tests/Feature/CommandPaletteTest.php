<?php

use App\Livewire\CommandPalette;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Acme Board']);
    $this->project->members()->attach($this->user);
    $this->story = Story::factory()->for($this->project)->create(['title' => 'Login flow']);
    $this->task = Task::factory()->for($this->story)->create(['title' => 'Deploy fix']);
});

it('finds a task by its title', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', 'Deploy')
        ->assertSee('Deploy fix');
});

it('finds a story by its title', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', 'Login')
        ->assertSee('Login flow');
});

it('finds a project by its short name', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', 'ABC')
        ->assertSee('Acme Board');
});

it('finds a task by its keyword', function () {
    $this->task->syncKeywords('urgent');

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', 'urgent')
        ->assertSee('Deploy fix');
});

it('finds a story by its keyword', function () {
    $this->story->syncKeywords('backend');

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', 'backend')
        ->assertSee('Login flow');
});

it('pins a jump result for a typed reference', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', $this->task->reference)
        ->assertSee('Deploy fix')
        ->assertSee($this->task->reference);
});

it('does not surface items from projects the user cannot access', function () {
    $otherProject = Project::factory()->create(['short_name' => 'XYZ']);
    $otherStory = Story::factory()->for($otherProject)->create();
    Task::factory()->for($otherStory)->create(['title' => 'Secret task']);

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', 'Secret')
        ->assertDontSee('Secret task');
});

it('does not jump to a reference the user cannot access', function () {
    $otherProject = Project::factory()->create(['short_name' => 'XYZ']);
    $otherStory = Story::factory()->for($otherProject)->create();
    $otherTask = Task::factory()->for($otherStory)->create(['title' => 'Secret task']);

    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', $otherTask->reference)
        ->assertDontSee('Secret task');
});

it('shows the New project action only to permitted users', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->assertDontSee('New project');

    $creator = User::factory()->canCreateProjects()->create();

    Livewire::actingAs($creator)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->assertSee('New project');
});

it('renders no result items while closed', function () {
    $creator = User::factory()->canCreateProjects()->create();
    $project = Project::factory()->create(['short_name' => 'DEF', 'title' => 'Closed Co']);
    $project->members()->attach($creator);

    Livewire::actingAs($creator)
        ->test(CommandPalette::class)
        ->set('query', 'Closed')
        ->assertDontSee('Closed Co')
        ->assertDontSee('New project');
});

it('clears its state when closed', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->set('open', true)
        ->set('query', 'Deploy')
        ->call('close')
        ->assertSet('open', false)
        ->assertSet('query', '');
});

it('navigates to the selected entry', function () {
    Livewire::actingAs($this->user)
        ->test(CommandPalette::class)
        ->call('go', route('dashboard'))
        ->assertRedirect(route('dashboard'));
});
