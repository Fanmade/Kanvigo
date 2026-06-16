<?php

use App\Livewire\Projects\ProjectList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('suggests a short name from the title when none is set', function () {
    Livewire::actingAs(User::factory()->canCreateProjects()->create())
        ->test(ProjectList::class)
        ->set('title', 'My Cool Project')
        ->assertSet('short_name', 'MCP');
});

it('does not overwrite a short name the user already entered', function () {
    Livewire::actingAs(User::factory()->canCreateProjects()->create())
        ->test(ProjectList::class)
        ->set('short_name', 'XYZ')
        ->set('title', 'My Cool Project')
        ->assertSet('short_name', 'XYZ');
});
