<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Role;
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

it('creates and edits a custom role from the detail pane', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user, 'owner');

    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    $this->actingAs($user);

    $page = visit("/{$project->short_name}/roles?role={$ownerRole->id}");

    // The owner role is held by this manager, so it is read-only.
    $page->assertVisible('@role-read-only-reason')
        ->assertMissing('@edit-role')
        ->click('@add-child-role')
        ->fill('@new-role-name', 'Triager')
        ->check('@new-permission-create-task')
        ->click('@save-new-role')
        ->assertSeeIn('@role-detail-name', 'Triager')
        ->assertSeeIn('@role-detail-permissions', 'Create');

    $role = Role::query()
        ->where('scope_id', $project->id)
        ->where('name', 'Triager')
        ->firstOrFail();

    // A child of Triager may only be granted what Triager holds — the rest is locked.
    $page->click('@add-child-role')
        ->assertVisible('@new-permission-bound-delete-project')
        ->click('@cancel-new-role');

    $page->click('@edit-role')
        ->fill('@edit-role-name', 'Triage lead')
        ->click('@save-role')
        ->assertSeeIn('@role-detail-name', 'Triage lead')
        ->assertNoJavascriptErrors();

    expect($role->fresh()->name)->toBe('Triage lead');
});
