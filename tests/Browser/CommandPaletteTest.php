<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['short_name' => 'ABC', 'title' => 'Acme Board']);
    joinProject($this->project, $this->user);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Deploy fix']);
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
        ->assertVisible('@task-actions')
        ->assertPathIs('/'.$this->task->reference)
        ->assertNoJavascriptErrors();
});

it('renders the keyboard-shortcut hint without a JS error', function () {
    $this->actingAs($this->user);

    $page = visit('/dashboard');

    // The hint must resolve to a platform label (never blank / "mac is not
    // defined") regardless of Alpine init timing — see KAN31. The test runs on a
    // non-Apple headless browser, so the label is "Ctrl K".
    $page->assertVisible('@command-palette-shortcut')
        ->assertSeeIn('@command-palette-shortcut', 'Ctrl K')
        ->assertNoJavascriptErrors();
});

it('hides the keyboard-shortcut hint on a mobile viewport', function () {
    $this->actingAs($this->user);

    // Below Tailwind's `sm` breakpoint the hint doesn't fit the narrow search bar
    // and isn't useful without a keyboard, so it's hidden — see KAN-183.
    $page = visit('/dashboard')->resize(375, 667);

    $page->assertVisible('@command-palette-trigger')
        ->assertMissing('@command-palette-shortcut')
        ->assertNoJavascriptErrors();
});

it('opens the create-project form from the New project action', function () {
    $user = User::factory()->canCreateProjects()->create();

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->click('@command-palette-trigger')
        ->fill('@command-palette-input', 'New project')
        ->click('@palette-item-new-project')
        ->assertVisible('@project-title')
        ->assertPathIs('/projects')
        ->assertNoJavascriptErrors();
});
