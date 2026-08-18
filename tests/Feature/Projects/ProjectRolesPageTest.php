<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Livewire\Projects\ProjectRoles;
use App\Models\Project;
use App\Models\User;
use Fanmade\DelegatedPermissions\Models\Role;
use Fanmade\DelegatedPermissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the visible role tree for a manager', function () {
    $project = Project::factory()->create();
    $owner = userWithRole($project, 'owner');

    $component = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['short_name' => $project->short_name])
        ->assertOk();

    $tree = collect($component->instance()->roleTree())->pluck('role.name');

    expect($tree)->toContain('owner', 'admin', 'member', 'viewer');
});

it('denies the page to a member without manage-roles', function () {
    $project = Project::factory()->create();
    $member = userWithRole($project, 'member');

    Livewire::actingAs($member)
        ->test(ProjectRoles::class, ['short_name' => $project->short_name])
        ->assertForbidden();
});

it('never shows an ancestor of the manager\'s own role', function () {
    $project = Project::factory()->create();
    userWithRole($project, 'owner');
    $ownerRole = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');

    $lead = app(RoleManager::class)->createRole('Lead', $ownerRole, ['view-project', 'manage-roles'], $project);
    app(RoleManager::class)->createRole('Triager', $lead, ['view-project'], $project);
    $manager = User::factory()->create()->assignRole($lead);

    $names = Livewire::actingAs($manager)
        ->test(ProjectRoles::class, ['short_name' => $project->short_name])
        ->instance()->roles()->pluck('name');

    expect($names)->toContain('Lead', 'Triager')
        ->and($names)->not->toContain('owner', 'admin');
});

it('selects the first visible role by default and deep-links the chosen one', function () {
    $project = Project::factory()->create();
    $owner = userWithRole($project, 'owner');
    $member = Role::query()
        ->where('scope_type', $project->getMorphClass())
        ->where('scope_id', $project->id)
        ->where('name', 'member')
        ->firstOrFail();

    $component = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['short_name' => $project->short_name]);

    expect($component->instance()->selectedRole()->name)->toBe('owner');

    $component->call('selectRole', $member->id)
        ->assertSet('selectedRoleId', $member->id);

    expect($component->instance()->selectedRole()->name)->toBe('member');
});

it('ignores a selection outside the manager\'s visible roles', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    userWithRole($other, 'owner');
    $owner = userWithRole($project, 'owner');

    $foreign = Role::query()
        ->where('scope_type', $other->getMorphClass())
        ->where('scope_id', $other->id)
        ->where('name', 'admin')
        ->firstOrFail();

    Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['short_name' => $project->short_name])
        ->call('selectRole', $foreign->id)
        ->assertSet('selectedRoleId', null);
});

it('groups the selected role\'s effective permissions', function () {
    $project = Project::factory()->create();
    $owner = userWithRole($project, 'owner');

    $groups = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['short_name' => $project->short_name])
        ->instance()->selectedRolePermissionGroups();

    expect($groups)->toHaveKey('Members & roles')
        ->and($groups['Tasks'])->not->toBeEmpty();
});

it('lists the members holding the selected role', function () {
    $project = Project::factory()->create();
    $owner = userWithRole($project, 'owner');
    $members = User::factory()->count(2)->create();
    joinProject($project, $members->all(), 'member');

    $memberRole = Role::query()
        ->where('scope_type', $project->getMorphClass())
        ->where('scope_id', $project->id)
        ->where('name', 'member')
        ->firstOrFail();

    $component = Livewire::actingAs($owner)
        ->test(ProjectRoles::class, ['short_name' => $project->short_name])
        ->call('selectRole', $memberRole->id);

    expect($component->instance()->selectedRoleMembers()->pluck('id'))
        ->toEqualCanonicalizing($members->pluck('id'));
});

it('counts role members without a query per role', function () {
    $countQueriesForTree = static function (int $extraRoles): int {
        $project = Project::factory()->create();
        $owner = userWithRole($project, 'owner');
        $ownerRole = Role::query()
            ->where('scope_type', $project->getMorphClass())
            ->where('scope_id', $project->id)
            ->where('name', 'owner')
            ->firstOrFail();

        for ($i = 0; $i < $extraRoles; $i++) {
            $role = app(RoleManager::class)->createRole('custom-'.$i, $ownerRole, ['view-project'], $project);
            User::factory()->create()->assignRole($role);
        }

        $component = Livewire::actingAs($owner)
            ->test(ProjectRoles::class, ['short_name' => $project->short_name]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->instance()->memberCounts();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($countQueriesForTree(10))->toBeLessThanOrEqual($countQueriesForTree(1));
});

it('links the project menu to the roles page instead of a modal', function () {
    $project = Project::factory()->create();
    $owner = userWithRole($project, 'owner');

    $this->actingAs($owner)
        ->get(route('project.show', $project))
        ->assertOk()
        ->assertSee(route('project.roles', $project), escape: false)
        ->assertDontSee('data-test="roles-modal"', escape: false);
});
