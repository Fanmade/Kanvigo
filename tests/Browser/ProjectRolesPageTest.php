<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\PermissionResolver;
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

it('retunes a seeded base role and restores its defaults', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user, 'owner');

    $member = app(ProjectRoleProvisioner::class)->roleFor($project, 'member');

    $this->actingAs($user);

    $page = visit("/{$project->short_name}/roles?role={$member->id}");

    // A base role is editable, but its name is fixed and it cannot be deleted.
    $page->assertVisible('@edit-role')
        ->assertVisible('@reset-role')
        ->assertMissing('@delete-role')
        ->click('@edit-role')
        ->uncheck('@edit-permission-create-task')
        ->click('@save-role')
        ->assertMissing('@edit-role-form')
        ->assertNoJavascriptErrors();

    expect(app(PermissionResolver::class)->permissionsFor($member->fresh())->all())
        ->not->toContain('create-task');
});

it('moves a custom role under another role from the detail pane', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['short_name' => 'ABC']);
    joinProject($project, $user, 'owner');

    $provisioner = app(ProjectRoleProvisioner::class);
    $ownerRole = $provisioner->roleFor($project, 'owner');
    $viewerRole = $provisioner->roleFor($project, 'viewer');
    $adminRole = $provisioner->roleFor($project, 'admin');

    // manage-settings sits in admin's set but not viewer's, so only admin is a
    // valid destination and viewer is offered but blocked.
    $role = app(RoleManager::class)
        ->createRole('Governor', $ownerRole, ['view-project', 'manage-settings'], $project);

    $this->actingAs($user);

    visit("/{$project->short_name}/roles?role={$role->id}")
        ->click('@move-role')
        ->assertVisible('@move-role-panel')
        ->assertVisible("@move-blocked-{$viewerRole->id}")
        ->click("@move-to-{$adminRole->id}")
        ->assertMissing('@move-role-panel')
        ->assertSeeIn('@role-detail-parent', 'admin')
        ->assertNoJavascriptErrors();

    expect($role->fresh()->parent_id)->toBe($adminRole->id);
});

it('shows a delegated manager only the permissions they hold', function () {
    $project = Project::factory()->create(['short_name' => 'ABC']);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $roles = app(RoleManager::class);

    $lead = $roles->createRole('Lead', $ownerRole, ['view-project', 'manage-roles', 'create-task'], $project);
    // Only the Lead role — joining as a member would add member's permissions
    // to the manager's own set and widen what the picker may offer.
    $manager = User::factory()->create()->assignRole($lead);

    $child = $roles->createRole('Triager', $lead, ['view-project'], $project);

    $this->actingAs($manager);

    visit("/{$project->short_name}/roles?role={$child->id}")
        ->click('@edit-role')
        ->assertVisible('@edit-permission-create-task')
        // Not held by the manager, so not offered at all — group included.
        ->assertMissing('@edit-permission-manage-members')
        ->assertMissing('@edit-permission-create-doc')
        ->assertNoJavascriptErrors();
});
