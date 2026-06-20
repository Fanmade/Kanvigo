<?php

use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Acme Board']);
    $this->project->members()->attach($this->user);
    $this->story = Story::factory()->for($this->project)->create(['title' => 'Login flow']);
    $this->task = Task::factory()->for($this->story)->create(['title' => 'Deploy fix']);
    $this->task->syncTags('urgent');
});

it('opens from the header and finds a task by tag', function () {
    $this->actingAs($this->user);

    $page = visit('/dashboard');

    $page->click('@command-palette-trigger')
        ->fill('@command-palette-input', 'urgent')
        ->assertSee('Deploy fix')
        ->assertNoJavascriptErrors();
});

it('jumps to a typed reference', function () {
    $this->actingAs($this->user);

    $page = visit('/dashboard');

    $page->click('@command-palette-trigger')
        ->fill('@command-palette-input', $this->task->reference)
        ->assertSee('Deploy fix')
        ->click('Deploy fix')
        ->assertPathIs('/'.$this->task->reference)
        ->assertNoJavascriptErrors();
});

it('opens the create-project form from the New project action', function () {
    $user = User::factory()->canCreateProjects()->create();

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->click('@command-palette-trigger')
        ->fill('@command-palette-input', 'New project')
        ->click('@palette-item-new-project')
        ->assertPathIs('/projects')
        ->assertVisible('@project-title')
        ->assertNoJavascriptErrors();
});
