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

it('edits a custom role in place and cascades a revoke to its descendants', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->create();
    joinProject($project, $owner, 'owner');

    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $roles = app(RoleManager::class);
    $resolver = app(PermissionResolver::class);

    $lead = $roles->createRole('Lead', $ownerRole, ['view-project', 'create-task', 'edit-task'], $project);
    $sub = $roles->createRole('Sub', $lead, ['view-project', 'edit-task'], $project);

    $ids = static fn (array $names): array => Permission::query()->whereIn('name', $names)->pluck('id')->all();

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->call('startEdit', $lead->id)
        // Keep view-project + create-task, drop edit-task, add manage-dependencies.
        ->set('editPermissionIds', $ids(['view-project', 'create-task', 'manage-dependencies']))
        ->call('saveRole')
        ->assertHasNoErrors();

    expect($resolver->permissionsFor($lead->fresh())->all())
        ->toEqualCanonicalizing(['view-project', 'create-task', 'manage-dependencies']);

    // The revoke of edit-task cascaded to the descendant Sub.
    expect($resolver->permissionsFor($sub->fresh())->all())->not->toContain('edit-task');
});

it('drops an out-of-bounds permission when editing a role', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->create();
    joinProject($project, $owner, 'owner');

    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $roles = app(RoleManager::class);

    // Sub's parent Lead lacks manage-settings, so it can never be granted to Sub.
    $lead = $roles->createRole('Lead', $ownerRole, ['view-project', 'create-task'], $project);
    $sub = $roles->createRole('Sub', $lead, ['view-project'], $project);

    $ids = Permission::query()->whereIn('name', ['view-project', 'create-task', 'manage-settings'])->pluck('id')->all();

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->call('startEdit', $sub->id)
        ->set('editPermissionIds', $ids)
        ->call('saveRole')
        ->assertHasNoErrors();

    expect(app(PermissionResolver::class)->permissionsFor($sub->fresh())->all())
        ->toEqualCanonicalizing(['view-project', 'create-task']);
});

it('will not open a protected base role for editing', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->create();
    joinProject($project, $owner, 'owner');

    $member = app(ProjectRoleProvisioner::class)->roleFor($project, 'member');

    $editingRoleId = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->call('startEdit', $member->id)
        ->get('editingRoleId');

    expect($editingRoleId)->toBeNull();
});

it('orders the visible roles as a hierarchy with each role\'s depth', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->create();
    joinProject($project, $owner, 'owner');

    // A custom role delegated under member sits one level below it.
    $memberRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'member');
    app(RoleManager::class)->createRole('Triager', $memberRole, ['view-project'], $project);

    $tree = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->instance()->roleTree();

    $depthByName = collect($tree)
        ->mapWithKeys(static fn (array $node): array => [$node['role']->name => $node['depth']])
        ->all();

    expect($depthByName)->toMatchArray([
        'owner' => 0,
        'admin' => 1,
        'member' => 2,
        'viewer' => 3,
        'Triager' => 3,
    ]);
});

it('prefills the new-role permissions from a role, capped by the chosen parent', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->create();
    joinProject($project, $owner, 'owner');

    $provisioner = app(ProjectRoleProvisioner::class);
    $ownerRole = $provisioner->roleFor($project, 'owner');
    $memberRole = $provisioner->roleFor($project, 'member');

    // Parent = member, copy from owner: the selection is capped to member's set.
    $component = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['project' => $project])
        ->set('parentId', $memberRole->id)
        ->call('selectRolePermissions', $ownerRole->id);

    $selected = Permission::query()
        ->whereKey($component->get('permissionIds'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($selected)->toBe(collect(ProjectRoleProvisioner::GRANTS['member'])->sort()->values()->all());
});
