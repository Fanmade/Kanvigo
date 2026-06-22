<?php

use App\Models\Project;
use App\Models\User;

it('renders the project description as formatted, clamped rich text on the card', function () {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'short_name' => 'DOC',
        'title' => 'Documented',
        'description' => '<p>Intro paragraph with <strong>bold</strong> and a <a href="https://example.com">link</a>.</p>'
            .'<p>Second paragraph.</p><p>Third paragraph.</p><p>Fourth paragraph that should be clamped away.</p>',
    ]);
    $project->members()->attach($user);

    $this->actingAs($user);

    $page = visit('/projects');

    $page->assertVisible('@project-card-description')
        ->assertSeeIn('@project-card-description', 'bold')
        // The raw HTML tags must not leak through as text.
        ->assertDontSee('<strong>')
        ->assertNoJavascriptErrors();
});

it('auto-fills the short name from the title in the new project modal', function () {
    $user = User::factory()->canCreateProjects()->create();

    $this->actingAs($user);

    $page = visit('/projects');

    $page->click('@create-project')
        ->fill('@project-title', 'My Cool Project')
        ->click('@project-short-name') // blur the title to fire wire:model.blur.live
        ->assertValue('@project-short-name', 'MCP')
        ->assertNoJavascriptErrors();
});
