<?php

use App\Models\User;

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
