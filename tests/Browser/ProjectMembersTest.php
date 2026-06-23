<?php

use App\Models\Project;
use App\Models\User;

it('lets the owner change a member\'s role through the management modal', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Casey Member']);
    $project = Project::factory()
        ->withOwner($owner)
        ->withMember($member)
        ->create(['short_name' => 'ABC']);

    $this->actingAs($owner);

    $page = visit('/ABC');
    $page->click('@project-actions')
        ->click('@manage-members')
        ->assertSee('Manage members')
        ->assertSee('Casey Member')
        ->select('@member-role-select-'.$member->id, 'admin')
        ->waitForText('Member role updated.')
        ->assertNoJavascriptErrors();

    expect($project->roleNameFor($member))->toBe('admin');
});

it('lets the owner add an existing user from the management modal', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create(['name' => 'Dana Newcomer']);
    $project = Project::factory()->withOwner($owner)->create(['short_name' => 'ABC']);

    $this->actingAs($owner);

    $page = visit('/ABC');
    $page->click('@project-actions')
        ->click('@manage-members')
        ->fill('@member-search', 'Dana')
        ->waitForText('Dana Newcomer')
        ->click('@add-user-'.$outsider->id)
        ->waitForText('Member added.')
        ->assertNoJavascriptErrors();

    expect($project->roleNameFor($outsider))->toBe('member');
});
