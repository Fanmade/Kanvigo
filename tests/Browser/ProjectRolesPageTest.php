<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\RoleManager;

it('reaches the roles page from the project menu and browses the role tree', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user, 'owner');

    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $lead = app(RoleManager::class)->createRole('Lead', $ownerRole, ['view-project', 'create-task'], $project);

    $holder = User::factory()->create(['name' => 'Robin Lead']);
    joinProject($project, $holder);
    $holder->assignRole($lead);

    $this->actingAs($user);

    $page = visit("/{$project->short_name}");

    $page->click('@project-actions')
        ->click('@manage-roles')
        ->assertVisible('@project-roles')
        ->assertMissing('@roles-modal')
        // The first visible role (owner) opens by default.
        ->assertSeeIn('@role-detail-name', 'owner')
        ->click("@role-row-{$lead->id}")
        ->assertSeeIn('@role-detail-name', 'Lead')
        ->assertSeeIn('@role-detail-members', 'Robin Lead')
        ->assertSeeIn('@role-detail-permissions', 'Create')
        ->assertNoJavascriptErrors();
});
