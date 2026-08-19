<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Livewire\Projects\ProjectList;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\PermissionGroup;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('provisions an owner→admin→member→viewer tree with the recomputed grants', function () {
    $project = Project::factory()->create();
    $roles = app(ProjectRoleProvisioner::class)->provision($project);

    $owner = User::factory()->create()->assignRole($roles['owner']);
    $admin = User::factory()->create()->assignRole($roles['admin']);
    $member = User::factory()->create()->assignRole($roles['member']);
    $viewer = User::factory()->create()->assignRole($roles['viewer']);

    // Owner — the whole catalog, including the owner-only abilities.
    expect($owner->can('manage-members', $project))->toBeTrue()
        ->and($owner->can('manage-roles', $project))->toBeTrue()
        ->and($owner->can('moderate-comments', $project))->toBeTrue();

    // Admin — settings, delete and comment moderation, but not member/role mgmt.
    expect($admin->can('manage-settings', $project))->toBeTrue()
        ->and($admin->can('delete-project', $project))->toBeTrue()
        ->and($admin->can('moderate-comments', $project))->toBeTrue()
        ->and($admin->can('manage-members', $project))->toBeFalse();

    // Member — the full contributor set, but no governance or moderation.
    expect($member->can('create-task', $project))->toBeTrue()
        ->and($member->can('edit-task', $project))->toBeTrue()
        ->and($member->can('tag-tasks', $project))->toBeTrue()
        ->and($member->can('manage-attachments', $project))->toBeTrue()
        ->and($member->can('create-comment', $project))->toBeTrue()
        ->and($member->can('manage-settings', $project))->toBeFalse()
        ->and($member->can('moderate-comments', $project))->toBeFalse();

    // Viewer — read-only.
    expect($viewer->can('view-project', $project))->toBeTrue()
        ->and($viewer->can('view-activity-log', $project))->toBeTrue()
        ->and($viewer->can('create-task', $project))->toBeFalse();
});

it('keeps each role a subset of the one above it', function () {
    foreach ([['viewer', 'member'], ['member', 'admin'], ['admin', 'owner']] as [$child, $parent]) {
        expect(array_diff(ProjectRoleProvisioner::GRANTS[$child], ProjectRoleProvisioner::GRANTS[$parent]))
            ->toBe([], "{$child} grants must be a subset of {$parent}");
    }

    // Owner holds exactly the flat catalog.
    expect(ProjectRoleProvisioner::GRANTS['owner'])->toBe(ProjectRoleProvisioner::CATALOG);
});

it('seeds the permission groups covering the whole catalog', function () {
    app(ProjectRoleProvisioner::class)->seedCatalog();

    foreach (ProjectRoleProvisioner::GROUPS as $name => $permissions) {
        $group = PermissionGroup::query()->where('name', $name)->first();

        expect($group)->not->toBeNull("group {$name} should be seeded")
            ->and($group->permissions()->pluck('name')->sort()->values()->all())
            ->toBe(collect($permissions)->sort()->values()->all());
    }

    // Every catalog permission belongs to exactly one group (no orphans, no dupes).
    expect(collect(ProjectRoleProvisioner::GROUPS)->flatten()->sort()->values()->all())
        ->toBe(collect(ProjectRoleProvisioner::CATALOG)->sort()->values()->all());
});

it('isolates access to the scoping project', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $roles = app(ProjectRoleProvisioner::class)->provision($project);

    $owner = User::factory()->create()->assignRole($roles['owner']);

    expect($owner->can('manage-settings', $project))->toBeTrue()
        ->and($owner->can('manage-settings', $other))->toBeFalse();
});

it('is idempotent', function () {
    $project = Project::factory()->create();
    $provisioner = app(ProjectRoleProvisioner::class);

    $provisioner->provision($project);
    $provisioner->provision($project);

    expect(Role::query()->where('scope_type', $project->getMorphClass())->where('scope_id', $project->getKey())->count())->toBe(4);
});

it('assigns the creator the owner role when a project is created through the dialog', function () {
    $user = User::factory()->canCreateProjects()->create();

    Livewire::actingAs($user)
        ->test(ProjectList::class)
        ->set('title', 'My Cool Project')
        ->set('short_name', 'mcp')
        ->set('description', 'A project.')
        ->call('createProject')
        ->assertHasNoErrors();

    $project = Project::where('short_name', 'MCP')->firstOrFail();

    expect($user->can('manage-members', $project))->toBeTrue()
        ->and($user->can('create-task', $project))->toBeTrue();
});

it('treats the seeded grants as defaults and leaves an edited base role alone', function () {
    $project = Project::factory()->create();
    $provisioner = app(ProjectRoleProvisioner::class);
    $resolver = app(PermissionResolver::class);

    $member = $provisioner->roleFor($project, 'member');
    $resolver->revoke($member, 'create-task');

    $provisioner->provision($project);

    expect($resolver->permissionsFor($member->fresh())->all())->not->toContain('create-task');
});
