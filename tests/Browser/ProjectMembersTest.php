<?php

use App\Models\Project;
use App\Models\User;

it('lets the owner add a role to a member through the management modal', function () {
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
        ->select('@add-member-role-'.$member->id, 'admin')
        ->waitForText('Member role added.')
        ->assertVisible('@member-role-'.$member->id.'-admin')
        ->assertNoJavascriptErrors();

    expect($project->roleNamesFor($member))->toBe(['admin', 'member']);
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
