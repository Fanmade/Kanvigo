<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Livewire\Projects\ProjectRoles;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Permission;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\PermissionResolver;
use Fanmade\DelegatedPermissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows an owner the whole project tree, including viewer, but never the system role', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->create();
    joinProject($project, $owner, 'owner');

    $names = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->instance()->roles()->pluck('name')->all();

    expect($names)->toContain('owner', 'admin', 'member', 'viewer')
        ->and($names)->not->toContain('system');
});

it('limits a delegated manager to their subtree and parent-bounded grants', function () {
    $project = Project::factory()->create();
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    // A "Lead" custom role that can manage roles and work on tasks — but not settings.
    $lead = app(RoleManager::class)->createRole(
        'Lead',
        $ownerRole,
        ['view-project', 'manage-roles', 'create-task', 'edit-task'],
        $project,
    );
    $manager = User::factory()->create()->assignRole($lead);

    $component = Livewire::actingAs($manager)->test(ProjectRoles::class, ['project' => $project]);

    // Visibility: the manager sees only their own role — never ancestors or base roles.
    expect($component->instance()->roles()->pluck('name')->all())->toBe(['Lead']);

    // The permission picker (parent defaults to Lead) offers only Lead's permissions.
    $offered = collect($component->instance()->permissionGroups())->flatten()->pluck('name');
    expect($offered)->toContain('create-task', 'edit-task', 'manage-roles')
        ->and($offered)->not->toContain('manage-settings', 'delete-project');

    // Creating a sub-role under Lead drops any out-of-bounds permission (settings).
    $editTask = Permission::where('name', 'edit-task')->value('id');
    $settings = Permission::where('name', 'manage-settings')->value('id');

    $component->set('name', 'Triager')
        ->set('parentId', $lead->id)
        ->set('permissionIds', [$editTask, $settings])
        ->call('createRole')
        ->assertHasNoErrors();

    $triager = Role::query()->where('scope_id', $project->id)->where('name', 'Triager')->first();

    expect($triager->parent_id)->toBe($lead->id)
        ->and(app(PermissionResolver::class)->permissionsFor($triager)->all())->toBe(['edit-task']);

    // Deeper-only: the manager may delete the role below them, not their own role.
    $deletable = $component->instance()->deletableRoleIds()->all();
    expect($deletable)->toContain($triager->id)
        ->and($deletable)->not->toContain($lead->id);
});

it('refuses to delete a protected base role', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->create();
    joinProject($project, $owner, 'owner');

    // The seeded member role is visible to the owner but protected.
    $memberRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'member');

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->call('deleteRole', $memberRole->id);

    expect(Role::query()->whereKey($memberRole->id)->exists())->toBeTrue();
});
