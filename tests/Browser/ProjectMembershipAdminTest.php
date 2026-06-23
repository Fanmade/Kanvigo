<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;

it('lets a system admin add a user to a project from user administration', function () {
    // Managing arbitrary project rosters now requires the system break-glass role
    // (KAN-240), not just account-level manage-users.
    $admin = User::factory()->canManageUsers()->create(['name' => 'Ada Admin'])
        ->assignRole(app(ProjectRoleProvisioner::class)->systemRole());
    $user = User::factory()->create(['name' => 'Casey User']);
    $project = Project::factory()->create(['title' => 'Apollo', 'short_name' => 'APO']);

    $this->actingAs($admin);

    $page = visit('/admin/users');
    $page->click('@manage-projects-'.$user->id)
        ->assertSee('Projects for Casey User')
        ->click('@mp-add-'.$project->id)
        ->waitForText('Member added.')
        ->assertNoJavascriptErrors();

    expect($project->roleNameFor($user))->toBe('member');
});
