<?php

use App\Models\Project;
use App\Models\User;

it('does not overflow the sidebar with a very long project name', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'short_name' => 'ABC',
        'title' => 'An Extremely Long Project Name That Would Otherwise Force A Horizontal Scrollbar In The Sidebar',
    ]);
    joinProject($project, $user);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('ABC')
        ->assertScript(
            "(() => { const el = document.querySelector('[data-flux-sidebar]'); return el.scrollWidth <= el.clientWidth; })()",
        )
        ->assertNoJavascriptErrors();
});
