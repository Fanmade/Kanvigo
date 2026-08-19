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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function projectOwner(Project $project): User
{
    return User::factory()->create()->assignRole(
        app(ProjectRoleProvisioner::class)->roleFor($project, 'owner')
    );
}

/**
 * The roles page, with the named role open in the detail pane.
 */
function rolesPage(User $manager, Project $project, ?Role $selected = null): Testable
{
    $component = Livewire::actingAs($manager)->test(ProjectRoles::class, ['short_name' => $project->short_name]);

    return $selected === null ? $component : $component->call('selectRole', $selected->id);
}

it('lists the seeded roles for an owner', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);

    $roles = rolesPage($owner, $project)->instance()->roles()->pluck('name');

    expect($roles)->toContain('owner', 'admin', 'member');
});

it('lets an owner define a custom role bounded by the project permissions', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $createTask = Permission::query()->where('name', 'create-task')->value('id');

    rolesPage($owner, $project, $ownerRole)
        ->call('startCreate')
        ->set('newName', 'Triager')
        ->set('newPermissionIds', [$createTask])
        ->call('createRole')
        ->assertHasNoErrors();

    $role = Role::query()->where('scope_id', $project->id)->where('name', 'Triager')->first();
    expect($role)->not->toBeNull();

    $triager = User::factory()->create()->assignRole($role);
    expect($triager->can('create-task', $project))->toBeTrue()
        ->and($triager->can('manage-settings', $project))->toBeFalse();
});

it('opens the new role in the detail pane', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    $component = rolesPage($owner, $project, $ownerRole)
        ->call('startCreate')
        ->set('newName', 'Triager')
        ->call('createRole')
        ->assertSet('creating', false);

    expect($component->instance()->selectedRole()->name)->toBe('Triager');
});

it('rejects a duplicate role name', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    rolesPage($owner, $project, $ownerRole)
        ->call('startCreate')
        ->set('newName', 'admin')
        ->call('createRole')
        ->assertHasErrors('newName');
});

it('will not delete a seeded base role', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $admin = Role::query()->where('scope_id', $project->id)->where('name', 'admin')->firstOrFail();

    rolesPage($owner, $project, $admin)->call('deleteRole');

    expect(Role::query()->whereKey($admin->id)->exists())->toBeTrue();
});

it('deletes a custom role and falls back to its parent', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    rolesPage($owner, $project, $ownerRole)
        ->call('startCreate')
        ->set('newName', 'Triager')
        ->call('createRole');

    $role = Role::query()->where('scope_id', $project->id)->where('name', 'Triager')->firstOrFail();

    rolesPage($owner, $project, $role)
        ->call('deleteRole')
        ->assertSet('selectedRoleId', $ownerRole->id);

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

it('names the deletion consequence', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    rolesPage($owner, $project, $ownerRole)
        ->call('startCreate')
        ->set('newName', 'Triager')
        ->call('createRole');

    $role = Role::query()->where('scope_id', $project->id)->where('name', 'Triager')->firstOrFail();
    User::factory()->create()->assignRole($role);

    $consequence = rolesPage($owner, $project, $role)->instance()->deleteConsequence();

    expect($consequence)->toContain('Triager', 'owner', '1 member');
});

it('renames a custom role and stores its description', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    rolesPage($owner, $project, $ownerRole)
        ->call('startCreate')
        ->set('newName', 'Triager')
        ->call('createRole');

    $role = Role::query()->where('scope_id', $project->id)->where('name', 'Triager')->firstOrFail();

    rolesPage($owner, $project, $role)
        ->call('startEdit')
        ->set('editName', 'Triage lead')
        ->set('editDescription', 'Sorts the inbox.')
        ->call('saveRole')
        ->assertHasNoErrors()
        ->assertSet('editing', false);

    expect($role->fresh()->name)->toBe('Triage lead')
        ->and($role->fresh()->description)->toBe('Sorts the inbox.');
});

it('renames a role in the same save as a permission change', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $role = app(RoleManager::class)->createRole('Triager', $ownerRole, ['view-project'], $project);
    $logId = Permission::query()->where('name', 'view-activity-log')->value('id');
    $viewId = Permission::query()->where('name', 'view-project')->value('id');

    rolesPage($owner, $project, $role)
        ->call('startEdit')
        ->set('editName', 'Triage lead')
        ->set('editPermissionIds', [$viewId, $logId])
        ->call('saveRole')
        ->assertHasNoErrors();

    expect($role->fresh()->name)->toBe('Triage lead')
        ->and(app(PermissionResolver::class)->permissionsFor($role->fresh())->all())
        ->toEqualCanonicalizing(['view-project', 'view-activity-log']);
});

it('rejects renaming a role onto an existing name', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    rolesPage($owner, $project, $ownerRole)
        ->call('startCreate')
        ->set('newName', 'Triager')
        ->call('createRole');

    $role = Role::query()->where('scope_id', $project->id)->where('name', 'Triager')->firstOrFail();

    rolesPage($owner, $project, $role)
        ->call('startEdit')
        ->set('editName', 'admin')
        ->call('saveRole')
        ->assertHasErrors('editName');

    expect($role->fresh()->name)->toBe('Triager');
});

it('forbids a non-owner from managing roles', function () {
    $project = Project::factory()->create();
    $member = User::factory()->create()->assignRole(
        app(ProjectRoleProvisioner::class)->roleFor($project, 'member')
    );

    Livewire::actingAs($member)
        ->test(ProjectRoles::class, ['short_name' => $project->short_name])
        ->assertForbidden();
});

it('keeps a role the manager holds themselves read-only', function () {
    $project = Project::factory()->create();
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $lead = app(RoleManager::class)->createRole('Lead', $ownerRole, ['view-project', 'manage-roles'], $project);
    $manager = User::factory()->create()->assignRole($lead);

    $component = rolesPage($manager, $project, $lead)
        ->call('startEdit')
        ->assertSet('editing', false);

    expect($component->instance()->readOnlyReason())
        ->toBe(__('You cannot edit a role you hold yourself.'));
});

it('bounds the edit picker to the parent role', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    $lead = app(RoleManager::class)
        ->createRole('Lead', $ownerRole, ['view-project', 'create-task'], $project);
    $sub = app(RoleManager::class)
        ->createRole('Sub', $lead, ['view-project'], $project);

    $allowed = rolesPage($owner, $project, $sub)->instance()->editAllowedPermissions();

    expect($allowed)->toEqualCanonicalizing(['view-project', 'create-task']);
});

it('restores a base role to its seeded permissions', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $provisioner = app(ProjectRoleProvisioner::class);
    $member = $provisioner->roleFor($project, 'member');
    $resolver = app(PermissionResolver::class);

    // Drift: drop a default and add one the parent (admin) also holds.
    $resolver->revoke($member, 'create-task');
    $resolver->grant($member, 'moderate-comments');

    rolesPage($owner, $project, $member)->call('resetToDefaults');

    expect($resolver->permissionsFor($member->fresh())->all())
        ->toEqualCanonicalizing(ProjectRoleProvisioner::GRANTS['member']);
});

it('offers no reset for a custom role or the owner role', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $custom = app(RoleManager::class)->createRole('Triager', $ownerRole, ['view-project'], $project);

    expect(rolesPage($owner, $project, $custom)->instance()->canResetSelected())->toBeFalse()
        ->and(rolesPage($owner, $project, $ownerRole)->instance()->canResetSelected())->toBeFalse();
});

it('skips a default the parent role no longer holds when resetting', function () {
    $project = Project::factory()->create();
    $owner = projectOwner($project);
    $provisioner = app(ProjectRoleProvisioner::class);
    $admin = $provisioner->roleFor($project, 'admin');
    $member = $provisioner->roleFor($project, 'member');
    $resolver = app(PermissionResolver::class);

    // The parent lost export-content, so member cannot be given it back.
    $resolver->revoke($admin, 'export-content');

    rolesPage($owner, $project, $member)->call('resetToDefaults');

    $restored = $resolver->permissionsFor($member->fresh());

    expect($restored)->not->toContain('export-content')
        ->and($restored)->toContain('create-task');
});
